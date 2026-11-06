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
     * GD 이미지 리소스에서 주어진 정규화 좌표 근방(반경 15px)의 인쇄된 흑색 점(•) 무게중심으로 스냅
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

        // [방안 C 안전망] 가로형 선잇기에서 AI가 왼쪽 번호 (1)의 좌표(x < 0.28)를 시작점으로 잘못 반환한 경우,
        // 번호 텍스트를 건너뛰고 중앙 불릿 밴드(x: 35%~58%)에서 실제 인쇄된 원형 점 •을 탐색
        if ($direction === 'horizontal') {
            if ($role === 'source' && $normX < 0.28) {
                $targetMinX = (int)round($cropW * 0.35);
                $targetMaxX = (int)round($cropW * 0.58);
                $targetMinY = max(0, (int)round($normY * $cropH) - 20);
                $targetMaxY = min($cropH - 1, (int)round($normY * $cropH) + 20);

                $bandSumX = 0;
                $bandSumY = 0;
                $bandCount = 0;
                for ($by = $targetMinY; $by <= $targetMaxY; $by++) {
                    for ($bx = $targetMinX; $bx <= $targetMaxX; $bx++) {
                        $rgb = imagecolorat($gdImg, $bx, $by);
                        $r = ($rgb >> 16) & 0xFF;
                        $g = ($rgb >> 8) & 0xFF;
                        $b = $rgb & 0xFF;
                        if ($r < 95 && $g < 95 && $b < 95) {
                            $bandSumX += $bx;
                            $bandSumY += $by;
                            $bandCount++;
                        }
                    }
                }
                if ($bandCount >= 4) {
                    return [
                        'norm_x' => max(0.0, min(1.0, ($bandSumX / $bandCount) / $cropW)),
                        'norm_y' => max(0.0, min(1.0, ($bandSumY / $bandCount) / $cropH))
                    ];
                }
            } elseif ($role === 'target' && $normX < 0.60) {
                $targetMinX = (int)round($cropW * 0.65);
                $targetMaxX = (int)round($cropW * 0.88);
                $targetMinY = max(0, (int)round($normY * $cropH) - 20);
                $targetMaxY = min($cropH - 1, (int)round($normY * $cropH) + 20);

                $bandSumX = 0;
                $bandSumY = 0;
                $bandCount = 0;
                for ($by = $targetMinY; $by <= $targetMaxY; $by++) {
                    for ($bx = $targetMinX; $bx <= $targetMaxX; $bx++) {
                        $rgb = imagecolorat($gdImg, $bx, $by);
                        $r = ($rgb >> 16) & 0xFF;
                        $g = ($rgb >> 8) & 0xFF;
                        $b = $rgb & 0xFF;
                        if ($r < 95 && $g < 95 && $b < 95) {
                            $bandSumX += $bx;
                            $bandSumY += $by;
                            $bandCount++;
                        }
                    }
                }
                if ($bandCount >= 4) {
                    return [
                        'norm_x' => max(0.0, min(1.0, ($bandSumX / $bandCount) / $cropW)),
                        'norm_y' => max(0.0, min(1.0, ($bandSumY / $bandCount) / $cropH))
                    ];
                }
            }
        }

        $cx = (int)round($normX * $cropW);
        $cy = (int)round($normY * $cropH);
        $searchRadius = 15;

        $sumX = 0;
        $sumY = 0;
        $darkCount = 0;

        $minX = max(0, $cx - $searchRadius);
        $maxX = min($cropW - 1, $cx + $searchRadius);
        $minY = max(0, $cy - $searchRadius);
        $maxY = min($cropH - 1, $cy + $searchRadius);

        for ($y = $minY; $y <= $maxY; $y++) {
            for ($x = $minX; $x <= $maxX; $x++) {
                if (hypot($x - $cx, $y - $cy) > $searchRadius) continue;
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

        // 인쇄된 원형 점 픽셀 군집(4개 이상) 발견 시 무게중심으로 정밀 스냅
        if ($darkCount >= 4) {
            $snappedX = $sumX / $darkCount;
            $snappedY = $sumY / $darkCount;
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

        $prompt = "You are an expert exam analyzer for Korean elementary and secondary school tests.
"
                . "Analyze this cropped exam question diagram image for a line-matching (선잇기) problem.
"
                . "{$answerContext}
"
                . "CRITICAL DIRECTION RULES (가로형 vs 세로형 엄격 구분):
"
                . "1. 'horizontal' (가로형 / 좌우 연결):
"
                . "   - The diagram is split into TWO COLUMNS: a LEFT column of items and a RIGHT column of items.
"
                . "   - Left column contains source items (e.g., (1), (2) with pictures/illustrations stacked vertically).
"
                . "   - Right column contains target items (e.g., ㉠, ㉡ with text labels stacked vertically).
"
                . "   - The lines connect HORIZONTALLY from the left column across to the right column!
"
                . "   - [IMPORTANT WARNING]: Even though items (1) and (2) on the left are stacked in rows one above another, the problem is STILL 'horizontal' because lines connect from LEFT to RIGHT across columns!
"
                . "2. 'vertical' (세로형 / 상하 연결):
"
                . "   - The diagram is split into TWO ROWS: a TOP row of items side-by-side and a BOTTOM row of items side-by-side.
"
                . "   - Top row contains source items (1), (2), (3) side-by-side.
"
                . "   - Bottom row contains target items ㉠, ㉡, ㉢ side-by-side.
"
                . "   - The lines connect VERTICALLY from top row down to bottom row.

"
                . "CRITICAL NEGATIVE RULES FOR ANCHOR DOTS (방안 C: 음성 제약 및 번호 위치 배제):\n"
                . "1. [ABSOLUTE NEGATIVE CONSTRAINT]: NEVER return the coordinates of question sub-numbers '(1)', '(2)', '1', '2' on the far left as source dots!\n"
                . "   - In Korean test papers, connection lines NEVER connect to the leftmost question number text '(1)'!\n"
                . "   - The connection dot is ALWAYS a small printed solid black circular dot ('•' or '●').\n"
                . "2. In 'horizontal' (가로형) matching:\n"
                . "   - SOURCE DOTS (Left column items):\n"
                . "     * The dot '•' is located AFTER the item image/text (to the right of the picture, in the central vertical strip of the problem, typically 0.38 <= norm_x <= 0.55).\n"
                . "     * It is a CRITICAL ERROR to return norm_x < 0.25 (which is the number '(1)' or '(2)')! Every source dot MUST have norm_x > 0.30!\n"
                . "   - TARGET DOTS (Right column items):\n"
                . "     * The dot '•' is located BEFORE the target label (to the left of '㉠', '㉡', typically 0.70 <= norm_x <= 0.85).\n"
                . "     * It is a CRITICAL ERROR to return norm_x < 0.60 for target dots!\n"
                . "   - Every connecting line MUST go across from the LEFT column dots (x ~ 0.45) to the RIGHT column dots (x ~ 0.77).\n"
                . "   - NEVER connect two items in the same column! (e.g. NEVER connect (2) to (1)!)\n"
                . "3. In 'vertical' (세로형) matching:\n"
                . "   - SOURCE DOTS (Top row): Located directly BELOW each top item (typically 0.35 <= norm_y <= 0.55).\n"
                . "   - TARGET DOTS (Bottom row): Located directly ABOVE each bottom item (typically 0.65 <= norm_y <= 0.85).\n"
                . "4. You MUST return the EXACT CENTER of the printed small circular dots ('•' / '●') as 'norm_x' and 'norm_y' (0.0 to 1.0).\n\n"
                . "Tasks:
"
                . "1. 'is_matching': boolean. Is this truly a line-matching question diagram?
"
                . "2. 'direction': 'horizontal' (left-to-right) or 'vertical' (top-to-bottom).
"
                . "3. 'source_label_style': string describing source item numbering (e.g. '(1)', '1', '·', 'ㄱ').
"
                . "4. 'target_label_style': string describing target destination markers (e.g. '㉠', 'ㄱ', 'A', '·').
"
                . "5. 'matching_box': the bounding box [ymin, xmin, ymax, xmax] enclosing ALL connecting items, pictures, options, boxes, and connection dots.
"
                . "6. 'source_dots': starting connection dots/anchors (e.g. (1), (2), (3)). Give 'label', exact center 'norm_x' (0.0~1.0), 'norm_y' (0.0~1.0) of the printed dot '•'.
"
                . "7. 'target_dots': destination connection dots/anchors (e.g. ㉠, ㉡, ㉢). Give 'label', exact center 'norm_x' (0.0~1.0), 'norm_y' (0.0~1.0) of the printed dot '•'.
"
                . "8. 'pairs': array of matching pairs if answer is provided or diagram has printed lines, e.g. [{\"from\": \"(1)\", \"to\": \"㉢\"}].
"
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
                CURLOPT_TIMEOUT        => 14,
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
            $topY = max(0, (int)round($qNumRoi['y'] - 10));
            $bottomY = ($nextQY !== null) ? max($topY + 100, (int)round($nextQY - 6)) : min($ph - 15, $topY + 500);

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

            // Gemini 분석 결과 선잇기 문항이 아니라고 판별된 경우
            // 선잇기(matching_line)를 제외한 유형 중에서 다시 분류한다.
            if (isset($gData['is_matching']) && !$gData['is_matching']) {
                imagedestroy($cropImg);
                imagedestroy($srcImg);

                // 기존 type/sub_type 정보를 최대한 살려 재분류하되,
                // 주관식(subjective) / 객관식(objective) 계열로만 나눈다.
                $prevType = $r['type'] ?? '';
                $prevSubType = $r['sub_type'] ?? null;

                if (in_array($prevType, ['subjective', 'short_answer', 'short', 'text', '주관식'])) {
                    $r['type'] = 'subjective';
                } elseif ($prevType === 'sub_item' && $prevSubType === 'subjective') {
                    $r['type'] = 'sub_item';
                } else {
                    $r['type'] = 'objective';
                }

                $r['sub_type'] = null;
                unset($r['matching_info']);
                continue;
            }
            // 1. matching_box가 있으면 ans_roi를 통합 다이어그램 전체 영역으로 교체 (스케일 자동 판정 및 Strict Clamping)

            if (!empty($gData['matching_box']) && isset($gData['matching_box']['ymin'])) {

                $mbox = $gData['matching_box'];

                $rawYmin = (float)($mbox['ymin'] ?? 0);

                $rawXmin = (float)($mbox['xmin'] ?? 0);

                $rawYmax = (float)($mbox['ymax'] ?? 1);

                $rawXmax = (float)($mbox['xmax'] ?? 1);



                $maxCoord = max($rawYmin, $rawXmin, $rawYmax, $rawXmax);

                $boxDivisor = 1.0;

                if ($maxCoord > 100.0) {

                    $boxDivisor = 1000.0;

                } elseif ($maxCoord > 1.0) {

                    $boxDivisor = 100.0;

                }



                $mYmin = max(0.0, min(1.0, $rawYmin / $boxDivisor));

                $mXmin = max(0.0, min(1.0, $rawXmin / $boxDivisor));

                $mYmax = max(0.0, min(1.0, $rawYmax / $boxDivisor));

                $mXmax = max(0.0, min(1.0, $rawXmax / $boxDivisor));



                if ($mXmax <= $mXmin) {

                    $mXmin = 0.05;

                    $mXmax = 0.95;

                }

                if ($mYmax <= $mYmin) {

                    $mYmin = 0.15;

                    $mYmax = 0.95;

                }



                $rawX = (int)round($cropX + $mXmin * $cropW);

                $rawY = (int)round($cropY + $mYmin * $cropH);

                $rawW = (int)round(($mXmax - $mXmin) * $cropW);

                $rawH = (int)round(($mYmax - $mYmin) * $cropH);



                // 단(Column) 경계 및 다음 문항 침범 엄격 클램핑 (Strict Clamping)

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



            // 2. dot들을 ans_roi 기준 정규화 좌표(0.0~1.0)로 변환 (스케일 일괄 정규화)

            $allDots = array_merge($gData['source_dots'] ?? [], $gData['target_dots'] ?? []);

            $maxDotCoord = 0.0;

            foreach ($allDots as $d) {

                $maxDotCoord = max($maxDotCoord, (float)($d['norm_x'] ?? 0), (float)($d['norm_y'] ?? 0));

            }

            $dotDivisor = 1.0;

            if ($maxDotCoord > 100.0) {

                $dotDivisor = 1000.0;

            } elseif ($maxDotCoord > 1.0) {

                $dotDivisor = 100.0;

            }



            $curAr = $r['ans_roi'] ?? ['x' => $cropX, 'y' => $cropY, 'w' => $cropW, 'h' => $cropH];

            $normSourceDots = [];

            foreach ($gData['source_dots'] ?? [] as $d) {
                $dnx = max(0.0, min(1.0, (float)($d['norm_x'] ?? 0) / $dotDivisor));
                $dny = max(0.0, min(1.0, (float)($d['norm_y'] ?? 0) / $dotDivisor));
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
                $dnx = max(0.0, min(1.0, (float)($d['norm_x'] ?? 0) / $dotDivisor));
                $dny = max(0.0, min(1.0, (float)($d['norm_y'] ?? 0) / $dotDivisor));
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
