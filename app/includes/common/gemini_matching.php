<?php
/**
 * includes/common/gemini_matching.php
 * Gemini Vision API를 이용한 선잇기(Matching Line) 문항 지능형 분석 모듈
 * 문항 크롭 이미지를 분석하여 방향(vertical/horizontal), 단일 통합 ROI, 시작/도착 기준점(Dots), 소문항-답안 라벨 스타일 및 아이템 박스 추출
 */

require_once dirname(__DIR__, 2) . '/config/env.php';

if (!function_exists('toNorm01')) {
    /**
     * Gemini Vision 등 AI 모델이 반환하는 다양한 스케일의 좌표를 [0.0, 1.0]으로 안전 정규화
     * (0.0~1.0: 그대로 유지, 1.0~100.0: 백분율 /100, 100.0~1000.0: Gemini 2D 표준 /1000)
     */
    function toNorm01($val): float {
        $v = (float)$val;
        if ($v <= 0.0) return 0.0;
        if ($v <= 1.0) return $v;
        if ($v <= 100.0) return $v / 100.0;
        if ($v <= 1000.0) return $v / 1000.0;
        return 1.0;
    }
}
if (!function_exists('snapDotToNearestDarkBulletInImage')) {
    /**
     * GD 이미지 리소스에서 주어진 정규화 좌표 근방의 인쇄된 흑색 점(•) 무게중심으로 적응형 스냅 (15px -> 24px -> 30px)
     *
     * @param resource|\GdImage $gdImg 크롭된 GD 이미지
     * @param float $normX 정규화 X (0.0~1.0)
     * @param float $normY 정규화 Y (0.0~1.0)
     * @param int $cropW 크롭 너비
     * @param int $cropH 크롭 높이
     * @return array ['norm_x' => float, 'norm_y' => float]
     */
    function snapDotToNearestDarkBulletInImage($gdImg, float $normX, float $normY, int $cropW, int $cropH, string $direction = 'auto', string $role = 'source'): array {
        if (!$gdImg || $cropW <= 0 || $cropH <= 0) {
            return ['norm_x' => $normX, 'norm_y' => $normY];
        }

        $cx = (int)round($normX * $cropW);
        $cy = (int)round($normY * $cropH);

        // 1단계: 15px, 2단계: 24px, 3단계: 30px 단계적 적응형 탐색
        // 작은 불릿 점(•, 대략 4~28픽셀)을 우선 감지하여 인접한 큰 글자(㉠ 기호 테두리 등)로 빨려들어가는 것을 방지
        $radii = [15, 24, 30];
        $lastSumX = 0;
        $lastSumY = 0;
        $lastDarkCount = 0;

        foreach ($radii as $rLimit) {
            $sumX = 0;
            $sumY = 0;
            $darkCount = 0;

            $minX = max(0, $cx - $rLimit);
            $maxX = min($cropW - 1, $cx + $rLimit);
            $minY = max(0, $cy - $rLimit);
            $maxY = min($cropH - 1, $cy + $rLimit);

            for ($y = $minY; $y <= $maxY; $y++) {
                for ($x = $minX; $x <= $maxX; $x++) {
                    if (hypot($x - $cx, $y - $cy) > $rLimit) continue;
                    $rgb = imagecolorat($gdImg, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    // 인쇄된 흑색 점 픽셀 판정 (RGB 각각 95 이하)
                    if ($r < 95 && $g < 95 && $b < 95) {
                        $sumX += $x;
                        $sumY += $y;
                        $darkCount++;
                    }
                }
            }

            $lastSumX = $sumX;
            $lastSumY = $sumY;
            $lastDarkCount = $darkCount;

            // 원형 점(불릿) 군집(4~28픽셀) 발견 시 즉시 가장 가까운 점의 무게중심으로 정밀 스냅
            if ($darkCount >= 4 && $darkCount <= 28) {
                $snappedX = $sumX / $darkCount;
                $snappedY = $sumY / $darkCount;
                return [
                    'norm_x' => max(0.0, min(1.0, $snappedX / $cropW)),
                    'norm_y' => max(0.0, min(1.0, $snappedY / $cropH))
                ];
            }
        }

        // 28픽셀 초과 군집이더라도 30px 이내에 유효 흑색 픽셀이 있다면 무게중심 스냅
        if ($lastDarkCount >= 4) {
            $snappedX = $lastSumX / $lastDarkCount;
            $snappedY = $lastSumY / $lastDarkCount;
            return [
                'norm_x' => max(0.0, min(1.0, $snappedX / $cropW)),
                'norm_y' => max(0.0, min(1.0, $snappedY / $cropH))
            ];
        }

        return ['norm_x' => $normX, 'norm_y' => $normY];
    }
}



if (!function_exists('analyzeMatchingQuestionWithGemini')) {
    /**
     * 선잇기 문항 영역 이미지(JPEG base64)를 Gemini API로 정밀 분석
     *
     * @param string $base64Jpeg data:image/jpeg;base64,... 또는 순수 base64
     * @param string $answerKey '(1)-㉢, (2)-㉡' 등 정답표 문자열
     * @return array
     */
    function analyzeMatchingQuestionWithGemini(string $base64Jpeg, string $answerKey = ''): array {
        $apiKey = (string)env('GEMINI_API_KEY', '');
        if (empty($apiKey)) {
            $apiKey = (string)env('GOOGLE_API_KEY', '');
        }

        if (empty($apiKey)) {
            return [
                'success' => false,
                'error'   => 'Gemini API Key가 설정되지 않았습니다.'
            ];
        }

        // data URL 접두사 제거
        if (strpos($base64Jpeg, 'base64,') !== false) {
            $parts = explode('base64,', $base64Jpeg);
            $rawBase64 = trim($parts[1]);
        } else {
            $rawBase64 = trim($base64Jpeg);
        }

                $answerContext = '';
        if ($answerKey !== '') {
            $answerContext = "
CRITICAL ANSWER KEY GUIDANCE (공식 정답표 정보):
"
                . "- The teacher's official answer key for this question is: \"{$answerKey}\".
"
                . "- This explicitly defines which source item connects to which target item!
"
                . "- For example, if answer key is '(1)-㉡, (2)-㉠', then (1) connects to ㉡, and (2) connects to ㉠.
"
                . "- NEVER connect (2) to (1), or ㉠ to ㉡! You MUST connect source to target as indicated!
";
        }

        $prompt = "You are an expert exam analyzer for Korean elementary and secondary school tests.\n"
                . "Analyze this cropped exam question diagram image for a line-matching (선잇기) problem.\n"
                . "{$answerContext}\n"
                . "[1. DIRECTION & LAYOUT DETERMINATION]\n"
                . "1. 'horizontal' (가로형 / 2-column layout):\n"
                . "   - Left column contains source items (e.g. (1), (2), (3) with text/pictures stacked vertically).\n"
                . "   - Right column contains target choices (e.g. ㉠, ㉡, ㉢ with text/pictures stacked vertically).\n"
                . "   - Connecting lines run HORIZONTALLY from Left column across to Right column.\n"
                . "   - Source dots '•': located on the RIGHT side of each left item/text.\n"
                . "   - Target dots '•': located on the LEFT side of each right choice.\n"
                . "2. 'vertical' (세로형 / 2-row layout):\n"
                . "   - Top row contains source items (e.g. (1), (2), (3) with pictures/text arranged side-by-side).\n"
                . "   - Bottom row contains target choices (e.g. ㉠, ㉡, ㉢ arranged side-by-side).\n"
                . "   - Connecting lines run VERTICALLY from Top row down to Bottom row.\n"
                . "   - Source dots '•': located directly BELOW each top item (1), (2), (3), aligned with its vertical center axis.\n"
                . "   - Target dots '•': located directly ABOVE each bottom choice ㉠, ㉡, ㉢, aligned with its vertical center axis.\n\n"
                . "[2. ANCHOR DOTS SSOT RULES (CRITICAL)]\n"
                . "- Connection anchor points are ALWAYS the small printed solid black circular dots ('•' or '●').\n"
                . "- NEVER return coordinates of question numbers, option boxes, or text; locate the EXACT center of each printed circular dot '•' (5~10px dot).\n"
                . "- Both 'norm_x' and 'norm_y' MUST be accurate normalized coordinates between 0.0 and 1.0 within this cropped image.\n\n"
                . "[3. DISAMBIGUATION FOR KOREAN TEST FORMAT (DO NOT CONFUSE)]\n"
                . "- In Korean test papers, target choices often appear in the format: '• ㉠ [choice text]' or '• ① [text]'.\n"
                . "- CRITICAL: Do NOT confuse the circled label ('㉠', '①') with the anchor dot! The anchor dot is the standalone small dot '•' printed BEFORE the circled label.\n"
                . "- Never return the center or left edge of the circled symbol '㉠'; pinpoint the center of the printed bullet dot '•' itself!\n\n"
                . "Tasks:\n"
                . "1. 'is_matching': boolean. Is this truly a line-matching question diagram?\n"
                . "2. 'direction': 'horizontal' or 'vertical'.\n"
                . "3. 'source_label_style': string describing source item numbering (e.g. '(1)', '1', 'ㄱ').\n"
                . "4. 'target_label_style': string describing target destination markers (e.g. '㉠', 'ㄱ', 'A').\n"
                . "5. 'matching_box': bounding box [ymin, xmin, ymax, xmax] (0.0 to 1.0) enclosing ALL connecting items, pictures, options, and connection dots.\n"
                . "6. 'source_dots': starting connection dots (e.g. (1), (2), (3)). Give 'label', exact center 'norm_x' (0.0~1.0), 'norm_y' (0.0~1.0) of the printed dot '•'.\n"
                . "7. 'target_dots': destination connection dots (e.g. ㉠, ㉡, ㉢). Give 'label', exact center 'norm_x' (0.0~1.0), 'norm_y' (0.0~1.0) of the printed dot '•'.\n"
                . "8. 'pairs': array of matching pairs if answer is provided or diagram has printed lines, e.g. [{\"from\": \"(1)\", \"to\": \"㉢\"}].\n"
                . "Return strictly valid JSON matching the schema.";

        $schema = [
            "type" => "OBJECT",
            "properties" => [
                "is_matching" => [
                    "type" => "BOOLEAN"
                ],
                "direction" => [
                    "type" => "STRING",
                    "enum" => ["vertical", "horizontal"]
                ],
                "source_label_style" => [
                    "type" => "STRING"
                ],
                "target_label_style" => [
                    "type" => "STRING"
                ],
                "matching_box" => [
                    "type" => "OBJECT",
                    "properties" => [
                        "ymin" => ["type" => "NUMBER"],
                        "xmin" => ["type" => "NUMBER"],
                        "ymax" => ["type" => "NUMBER"],
                        "xmax" => ["type" => "NUMBER"]
                    ],
                    "required" => ["ymin", "xmin", "ymax", "xmax"]
                ],
                "source_dots" => [
                    "type" => "ARRAY",
                    "items" => [
                        "type" => "OBJECT",
                        "properties" => [
                            "label"    => ["type" => "STRING"],
                            "norm_x"   => ["type" => "NUMBER"],
                            "norm_y"   => ["type" => "NUMBER"],
                            "item_box" => [
                                "type" => "OBJECT",
                                "properties" => [
                                    "ymin" => ["type" => "NUMBER"],
                                    "xmin" => ["type" => "NUMBER"],
                                    "ymax" => ["type" => "NUMBER"],
                                    "xmax" => ["type" => "NUMBER"]
                                ]
                            ]
                        ],
                        "required" => ["label", "norm_x", "norm_y"]
                    ]
                ],
                "target_dots" => [
                    "type" => "ARRAY",
                    "items" => [
                        "type" => "OBJECT",
                        "properties" => [
                            "label"    => ["type" => "STRING"],
                            "norm_x"   => ["type" => "NUMBER"],
                            "norm_y"   => ["type" => "NUMBER"],
                            "item_box" => [
                                "type" => "OBJECT",
                                "properties" => [
                                    "ymin" => ["type" => "NUMBER"],
                                    "xmin" => ["type" => "NUMBER"],
                                    "ymax" => ["type" => "NUMBER"],
                                    "xmax" => ["type" => "NUMBER"]
                                ]
                            ]
                        ],
                        "required" => ["label", "norm_x", "norm_y"]
                    ]
                ],
                "pairs" => [
                    "type" => "ARRAY",
                    "items" => [
                        "type" => "OBJECT",
                        "properties" => [
                            "from" => ["type" => "STRING"],
                            "to"   => ["type" => "STRING"]
                        ],
                        "required" => ["from", "to"]
                    ]
                ]
            ],
            "required" => ["is_matching", "direction", "matching_box", "source_dots", "target_dots"]
        ];

        $payload = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $prompt],
                        [
                            "inline_data" => [
                                "mime_type" => "image/jpeg",
                                "data" => $rawBase64
                            ]
                        ]
                    ]
                ]
            ],
            "generationConfig" => [
                "response_mime_type" => "application/json",
                "temperature"        => 0.0,
                "maxOutputTokens"    => 1000,
                "response_schema"    => $schema
            ]
        ];

        $envModel = (string)env('GEMINI_MODEL', 'gemini-3.5-flash-lite');
        $candidateModels = array_values(array_unique([
            $envModel,
            'gemini-3.5-flash-lite',
            'gemini-3.5-flash',
            'gemini-3.1-flash-lite',
            'gemini-3.8-flash',
            'gemini-flash-latest'
        ]));

        $candidateList = [];
        foreach ($candidateModels as $cMod) {
            $candidateList[] = ['ver' => 'v1beta', 'model' => $cMod];
        }

        $response = null;
        $httpCode = 0;

        foreach ($candidateList as $cand) {
            $candVer   = $cand['ver'];
            $candModel = $cand['model'];
            $apiUrl    = "https://generativelanguage.googleapis.com/{$candVer}/models/{$candModel}:generateContent?key=" . urlencode($apiKey);

            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                break;
            }
        }

        if ($httpCode !== 200 || empty($response)) {
            return [
                'success' => false,
                'error'   => "Gemini API 호출 실패 (HTTP {$httpCode})"
            ];
        }

        $resJson = json_decode($response, true);
        $textOutput = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $parsedData = json_decode($textOutput, true);

        if (!is_array($parsedData)) {
            return [
                'success' => false,
                'error'   => 'Gemini 응답 JSON 파싱 실패'
            ];
        }

        return [
            'success' => true,
            'data'    => $parsedData
        ];
    }
}

if (!function_exists('autoAnalyzeMatchingRoisServerSide')) {
    /**
     * 템플릿의 roi 목록 중 선잇기(matching_line) 문항을 자동으로 찾아
     * 서버 측에서 해당 문항 영역을 크롭하고 Gemini Vision으로 분석하여
     * 최적 ans_roi, 방향, 기준점(Dots), 아이템 박스 정보를 자동 반영
     *
     * @param array &$rois templates.roi_data 배열 (참조)
     * @param array $pagesData templates.pages_data 배열
     * @return int 분석 완료된 선잇기 문항 수
     */
    function autoAnalyzeMatchingRoisServerSide(array &$rois, array $pagesData): int {
        if (empty($rois) || empty($pagesData)) return 0;

        // 페이지 맵 구성
        $pageMap = [];
        foreach ($pagesData as $p) {
            $pUid = $p['page_uid'] ?? ('p_' . ($p['page_num'] ?? 1));
            $pageMap[$pUid] = $p;
            $pageMap[(string)($p['page_num'] ?? 1)] = $p;
        }

        $analyzedCount = 0;
        $totalRois = count($rois);

        for ($i = 0; $i < $totalRois; $i++) {
            $r = &$rois[$i];
            $isMatching = ($r['type'] === 'matching_line' || $r['sub_type'] === 'matching_line');

            // 이미 정답표 기반으로 Gemini 정밀 분석이 완료된 문항은 스킵 (답안이 주어졌는데 아직 분석 안 되었거나 답안 없이 분석된 경우 재실행)
            $alreadyAnalyzedWithAnswer = !empty($r['matching_info']) && !empty($r['matching_info']['has_answer_guidance']);
            if (!$isMatching || $alreadyAnalyzedWithAnswer) {
                continue;
            }

            $pUid = $r['page_uid'] ?? ('p_' . ($r['page'] ?? 1));
            $pInfo = $pageMap[$pUid] ?? ($pageMap[(string)($r['page'] ?? 1)] ?? null);
            if (!$pInfo || empty($pInfo['image_path'])) {
                continue;
            }

            $physPath = APP_ROOT . $pInfo['image_path'];
            if (!file_exists($physPath)) {
                continue;
            }

            $pw = (int)($pInfo['width'] ?? 0);
            $ph = (int)($pInfo['height'] ?? 0);
            if ($pw <= 0 || $ph <= 0) {
                $imgSize = @getimagesize($physPath);
                if ($imgSize) {
                    $pw = $imgSize[0];
                    $ph = $imgSize[1];
                } else {
                    $pw = 1200;
                    $ph = 1700;
                }
            }

            $qNumRoi = $r['num_roi'] ?? ['x' => 50, 'y' => 50, 'w' => 40, 'h' => 30];
            $qAnsRoi = $r['ans_roi'] ?? ['x' => 100, 'y' => 50, 'w' => 400, 'h' => 200];

            $isLeftCol = ($qNumRoi['x'] < $pw * 0.5);
            $isLeft = $isLeftCol;

            // 동일 페이지, 동일 단의 다음 문항 찾기
            $nextQY = null;
            for ($j = $i + 1; $j < $totalRois; $j++) {
                $other = $rois[$j];
                $oPage = $other['page_uid'] ?? ('p_' . ($other['page'] ?? 1));
                if ($oPage === $pUid || (int)($other['page'] ?? 0) === (int)($r['page'] ?? 0)) {
                    $oNumRoi = $other['num_roi'] ?? null;
                    if ($oNumRoi) {
                        $oIsLeft = ($oNumRoi['x'] < $pw * 0.5);
                        if ($oIsLeft === $isLeftCol && $oNumRoi['y'] > $qNumRoi['y'] + 15) {
                            $nextQY = (float)$oNumRoi['y'];
                            break;
                        }
                    }
                }
            }

            // 크롭 영역 계산 (문항 시작점부터 다음 문항 시작점 직전까지)
            $topY = max(0, (int)round($qNumRoi['y'] - 40));   // 문항 번호 박스 위 40px까지 포함 (기존 -10 → -40)
            $bottomY = ($nextQY !== null)
    ? max($topY + 200, (int)round($nextQY - 6))    // 다음 문항 전 + 최소 200px 확보 (기존 +100 → +200)
    : min($ph - 15, $topY + 700);                  // 마지막 문항: 최대 700px까지 확장 (기존 500 → 700)

            $cropX = $isLeft ? max(0, (int)round($pw * 0.04)) : max(0, (int)round($pw * 0.50));
            $cropW = (int)round($pw * 0.46);
            $cropY = $topY;
            $cropH = $bottomY - $topY;

            if ($cropX + $cropW > $pw) $cropW = $pw - $cropX;
            if ($cropY + $cropH > $ph) $cropH = $ph - $cropY;
            if ($cropW < 50 || $cropH < 50) continue;

            // GD를 이용한 이미지 크롭
            $srcImg = @imagecreatefromstring(file_get_contents($physPath));
            if (!$srcImg) continue;

            $cropImg = imagecreatetruecolor($cropW, $cropH);
            imagecopy($cropImg, $srcImg, 0, 0, $cropX, $cropY, $cropW, $cropH);

            ob_start();
            imagejpeg($cropImg, null, 88);
            $jpegRaw = ob_get_clean();

            if (empty($jpegRaw)) {
                imagedestroy($cropImg);
                imagedestroy($srcImg);
                continue;
            }

            $geminiRes = analyzeMatchingQuestionWithGemini(base64_encode($jpegRaw), $r['answer'] ?? '');
            if (!$geminiRes['success'] || empty($geminiRes['data'])) {
                imagedestroy($cropImg);
                imagedestroy($srcImg);
                continue;
            }

            $gData = $geminiRes['data'];

            // [DEBUG] 검증 4: Gemini 원본 응답 로깅
            if (!empty($gData['source_dots']) || !empty($gData['target_dots'])) {
                error_log(sprintf(
                    "[DEBUG] Q%s Gemini 원본 응답 (검증4): direction=%s "
                    . "source_dots=%s target_dots=%s",
                    $r['display_num'],
                    $gData['direction'] ?? 'N/A',
                    json_encode($gData['source_dots'] ?? []),
                    json_encode($gData['target_dots'] ?? [])
                ));
            }

            // Gemini 분석 결과 선잇기 문항이 아니라고 판별된 경우 (예: Q17 등 일반 기호형/서술형)
            if (isset($gData['is_matching']) && !$gData['is_matching']) {
                imagedestroy($cropImg);
                imagedestroy($srcImg);
                $r['type'] = !empty($r['criteria']) ? 'essay' : 'short_answer';
                $r['sub_type'] = 'subjective';
                unset($r['matching_info']);
                continue;
            }


            // 1. matching_box가 있으면 ans_roi를 통합 다이어그램 전체 영역으로 교체 (toNorm01 독립 정규화 적용)
            if (!empty($gData['matching_box']) && isset($gData['matching_box']['ymin'])) {
                $mbox = $gData['matching_box'];
                $mYmin = toNorm01($mbox['ymin'] ?? 0);
                $mXmin = toNorm01($mbox['xmin'] ?? 0);
                $mYmax = toNorm01($mbox['ymax'] ?? 1);
                $mXmax = toNorm01($mbox['xmax'] ?? 1);

                // 감지된 점들이 matching_box 바깥에 있어 클램핑되는 현상 방지: 점들을 모두 포함하도록 박스 확장 (2% 마진)
                $allDots = array_merge($gData['source_dots'] ?? [], $gData['target_dots'] ?? []);
                foreach ($allDots as $d) {
                    $dx = toNorm01($d['norm_x'] ?? 0);
                    $dy = toNorm01($d['norm_y'] ?? 0);
                    if ($dx > 0) {
                        $mXmin = min($mXmin, max(0.0, $dx - 0.02));
                        $mXmax = max($mXmax, min(1.0, $dx + 0.02));
                    }
                    if ($dy > 0) {
                        $mYmin = min($mYmin, max(0.0, $dy - 0.02));
                        $mYmax = max($mYmax, min(1.0, $dy + 0.02));
                    }
                }

                if ($mXmax <= $mXmin) { $mXmin = 0.05; $mXmax = 0.95; }
                if ($mYmax <= $mYmin) { $mYmin = 0.15; $mYmax = 0.95; }

                $rawX = (int)round($cropX + $mXmin * $cropW);
                $rawY = (int)round($cropY + $mYmin * $cropH);
                $rawW = (int)round(($mXmax - $mXmin) * $cropW);
                $rawH = (int)round(($mYmax - $mYmin) * $cropH);

                $colRight = $cropX + $cropW;
                $newX = max($cropX, min($colRight - 40, $rawX));
                $newW = max(40, min($colRight - $newX, $rawW));
                $newY = max($cropY, min($bottomY - 30, $rawY));
                $newH = max(40, min($bottomY - $newY, $rawH));

                $r['ans_roi'] = [
                    'x' => $newX,
                    'y' => $newY,
                    'w' => $newW,
                    'h' => $newH
                ];
            }

            $curAr = $r['ans_roi'] ?? ['x' => $cropX, 'y' => $cropY, 'w' => $cropW, 'h' => $cropH];

            $normSourceDots = [];
            foreach ($gData['source_dots'] ?? [] as $d) {
                $dnx = toNorm01($d['norm_x'] ?? 0);
                $dny = toNorm01($d['norm_y'] ?? 0);
                if (!empty($cropImg)) {
                    $snapped = snapDotToNearestDarkBulletInImage($cropImg, $dnx, $dny, $cropW, $cropH, (string)($gData['direction'] ?? 'vertical'), 'source');
                    $dnx = $snapped['norm_x'];
                    $dny = $snapped['norm_y'];
                }
                $absX = $cropX + $dnx * $cropW;
                $absY = $cropY + $dny * $cropH;
                $roiNormX = ($curAr['w'] > 0) ? max(0.0, min(1.0, ($absX - $curAr['x']) / $curAr['w'])) : $dnx;
                $roiNormY = ($curAr['h'] > 0) ? max(0.0, min(1.0, ($absY - $curAr['y']) / $curAr['h'])) : $dny;
                $normSourceDots[] = [
                    'label'      => (string)($d['label'] ?? ''),
                    'abs_x'      => (float)$absX,
                    'abs_y'      => (float)$absY,
                    'roi_norm_x' => (float)$roiNormX,
                    'roi_norm_y' => (float)$roiNormY,
                    'item_box'   => $d['item_box'] ?? null
                ];
            }

            $normTargetDots = [];
            foreach ($gData['target_dots'] ?? [] as $d) {
                $dnx = toNorm01($d['norm_x'] ?? 0);
                $dny = toNorm01($d['norm_y'] ?? 0);
                if (!empty($cropImg)) {
                    $snapped = snapDotToNearestDarkBulletInImage($cropImg, $dnx, $dny, $cropW, $cropH, (string)($gData['direction'] ?? 'vertical'), 'target');
                    $dnx = $snapped['norm_x'];
                    $dny = $snapped['norm_y'];
                }
                $absX = $cropX + $dnx * $cropW;
                $absY = $cropY + $dny * $cropH;
                $roiNormX = ($curAr['w'] > 0) ? max(0.0, min(1.0, ($absX - $curAr['x']) / $curAr['w'])) : $dnx;
                $roiNormY = ($curAr['h'] > 0) ? max(0.0, min(1.0, ($absY - $curAr['y']) / $curAr['h'])) : $dny;
                $normTargetDots[] = [
                    'label'      => (string)($d['label'] ?? ''),
                    'abs_x'      => (float)$absX,
                    'abs_y'      => (float)$absY,
                    'roi_norm_x' => (float)$roiNormX,
                    'roi_norm_y' => (float)$roiNormY,
                    'item_box'   => $d['item_box'] ?? null
                ];
            }

            $r['matching_info'] = [
                'is_matching'        => true,
                'direction'          => (string)($gData['direction'] ?? 'vertical'),
                'source_label_style' => (string)($gData['source_label_style'] ?? '(1)'),
                'target_label_style' => (string)($gData['target_label_style'] ?? '㉠'),
                'matching_box'       => $gData['matching_box'] ?? null,
                'source_dots'        => $normSourceDots,
                'target_dots'        => $normTargetDots,
                'tolerance_radius'   => 20.0,
                'pairs'              => !empty($gData['pairs']) ? $gData['pairs'] : ($r['pairs'] ?? []),
                'has_answer_guidance' => !empty($r['answer']),
                'analyzed_at'        => date('Y-m-d H:i:s')
            ];
            if (!empty($cropImg)) { imagedestroy($cropImg); $cropImg = null; }
            if (!empty($srcImg)) { imagedestroy($srcImg); $srcImg = null; }
            $r['matching_direction'] = $r['matching_info']['direction'];

            // 3. 정답이 비어있고 Gemini가 pairs를 감지한 경우 자동 보완
            if (empty($r['answer']) && !empty($gData['pairs'])) {
                $pairStrs = [];
                foreach ($gData['pairs'] as $gp) {
                    $pairStrs[] = "{$gp['from']}-{$gp['to']}";
                }
                $r['answer'] = implode(', ', $pairStrs);
            }

            $analyzedCount++;
        }

        return $analyzedCount;
    }
}