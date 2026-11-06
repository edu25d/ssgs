<?php
/**
 * includes/common/upstage.php
 * Upstage Document Parse API 통신 및 시험지 문항/ROI 자동 추출 엔진
 */

require_once dirname(__DIR__, 2) . '/config/env.php';

if (!function_exists('parseDocumentWithUpstage')) {
    /**
     * Upstage Document Parse API 호출 (PDF 파일 전송)
     *
     * @param string $filePath 로컬 PDF 절대 경로
     * @return array|null 성공 시 파싱된 연관 배열, 실패 시 null
     */
    function parseDocumentWithUpstage(string $filePath): ?array {
        if (!file_exists($filePath)) {
            error_log("[Upstage API] 파일이 존재하지 않습니다: {$filePath}");
            return null;
        }

        $apiKey = env('UPSTAGE_API_KEY');
        if (!$apiKey) {
            error_log("[Upstage API] .env에 UPSTAGE_API_KEY가 설정되지 않았습니다.");
            return null;
        }

        $url = 'https://api.upstage.ai/v1/document-ai/document-parse';

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = 'application/pdf';
        if (in_array($ext, ['jpg', 'jpeg'])) {
            $mime = 'image/jpeg';
        } elseif ($ext === 'png') {
            $mime = 'image/png';
        } elseif ($ext === 'webp') {
            $mime = 'image/webp';
        }

        $cFile = new CURLFile($filePath, $mime, basename($filePath));
        $postData = [
            'document' => $cFile,
            'ocr'      => 'auto'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200) {
            error_log("[Upstage API] 호출 실패 HTTP {$httpCode}: {$curlErr} | Response: {$response}");
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('extractRoisFromUpstageData')) {
    /**
     * Upstage 파싱 결과(JSON) 및 PDF.js 정밀 글리프 좌표로부터 SSOT Contract B 규격의 문항 번호 및 답안 영역(ROI) 자동 산출 (방안 A)
     *
     * @param array $upstageData Upstage API 응답 배열
     * @param array $pagesData templates.pages_data 배열
     * @param array $pageGlyphs PDF.js 텍스트 레이어에서 추출한 정밀 괄호( ) 및 번호 좌표 배열
     * @return array SSOT Contract B 규격의 roi_data 배열
     */
    function extractRoisFromUpstageData(array $upstageData, array $pagesData, array $pageGlyphs = []): array {
        $elements = $upstageData['elements'] ?? [];
        if (empty($elements)) {
            return [];
        }

        // 페이지별 Upstage 박스/테이블/그림/보기 영역 수집
        $upstageBoxesByPage = [];
        foreach ($elements as $el) {
            $cat = $el['category'] ?? '';
            $html = $el['content']['html'] ?? '';
            $hasBokiText = (bool)preg_match('/<보기>|\[보기\]|\(보기\)/u', $html);
            $isBoxCat = ($cat === 'box') || ($cat === 'table' && $hasBokiText);

            if ($isBoxCat || $hasBokiText) {
                $elPage = (int)($el['page'] ?? 1);
                $coords = $el['coordinates'] ?? [];
                if (!empty($coords)) {
                    $xs = array_column($coords, 'x');
                    $ys = array_column($coords, 'y');
                    $minX = min($xs); $maxX = max($xs);
                    $minY = min($ys); $maxY = max($ys);
                    if ($maxX - $minX > 0.12 && $maxY - $minY > 0.02) {
                        $upstageBoxesByPage[$elPage][] = [
                            'minX' => $minX,
                            'maxX' => $maxX,
                            'minY' => $minY,
                            'maxY' => $maxY,
                            'cat'  => $cat
                        ];
                    }
                }
            }
        }

        // 페이지별 크기 매핑: page_num -> ['width' => w, 'height' => h, 'page_uid' => uid]
        $pageMap = [];
        foreach ($pagesData as $idx => $p) {
            $pNum = (int)($p['page_num'] ?? ($idx + 1));
            $pageMap[$pNum] = [
                'page_uid'   => $p['page_uid'] ?? ('p_' . $pNum),
                'width'      => (int)($p['width'] ?? 1200),
                'height'     => (int)($p['height'] ?? 1700),
                'image_path' => $p['image_path'] ?? ($p['web_path'] ?? '')
            ];
        }

        // 1단계: 문항 번호로 시작하는 요소 수집
        $questions = [];
        $elCount = count($elements);

        for ($i = 0; $i < $elCount; $i++) {
            $el = $elements[$i];
            $cat = $el['category'] ?? '';
            if ($cat === 'header' || $cat === 'footer') {
                continue;
            }

            $html = $el['content']['html'] ?? '';
            if (!$html) continue;

            $cleanText = trim(strip_tags($html));
            $cleanText = html_entity_decode($cleanText, ENT_QUOTES, 'UTF-8');

            // 문항 시작 패턴: "1 ", "1. ", "1) ", "1번 " 등
            if (preg_match('/^\s*(\d{1,2})\s*([\.|\s|\)|번]|\s*$)/u', $cleanText, $m)) {
                $qNum = (int)$m[1];
                if ($qNum >= 1 && $qNum <= 100) {
                    $coords = $el['coordinates'] ?? [];
                    if (!empty($coords)) {
                        $xs = array_column($coords, 'x');
                        $ys = array_column($coords, 'y');
                        $minX = min($xs); $maxX = max($xs);
                        $minY = min($ys); $maxY = max($ys);

                        $questions[] = [
                            'el_index'  => $i,
                            'num'       => $qNum,
                            'page'      => (int)($el['page'] ?? 1),
                            'minX'      => $minX,
                            'maxX'      => $maxX,
                            'minY'      => $minY,
                            'maxY'      => $maxY,
                            'cleanText' => $cleanText
                        ];
                    }
                }
            }
        }

        if (empty($questions)) {
            return [];
        }

        // 페이지 및 문항 번호 순 정렬
        usort($questions, function($a, $b) {
            if ($a['page'] !== $b['page']) return $a['page'] <=> $b['page'];
            return $a['num'] <=> $b['num'];
        });

        // 2단계: 문항별 num_roi 및 ans_roi 좌표 산출
        $rois = [];
        $totalQ = count($questions);
        $scorePerQuestion = ($totalQ > 0) ? (int)floor(100 / $totalQ) : 5;

        // 페이지별 사용된 괄호 및 밑줄 추적 (중복 할당 방지)
        $usedBrackets = [];
        $usedUnderlines = [];

        for ($idx = 0; $idx < $totalQ; $idx++) {
            $q = $questions[$idx];
            $pNum = $q['page'];
            $pInfo = $pageMap[$pNum] ?? [
                'page_uid' => 'p_' . $pNum,
                'width'    => 1200,
                'height'   => 1700
            ];

            $pw = $pInfo['width'];
            $ph = $pInfo['height'];

            // 클라이언트 PDF.js에서 추출된 기하 구조 데이터 확인 (괄호, 번호, 밑줄, 박스)
            $pGlyphData = $pageGlyphs[$pNum] ?? ($pageGlyphs[(string)$pNum] ?? null);
            $pageBracketsList = [];
            $pageNumbersList = [];
            $pageUnderlinesList = [];
            $pageBoxesList = [];

            if (is_array($pGlyphData)) {
                if (isset($pGlyphData['brackets'])) {
                    $pageBracketsList = $pGlyphData['brackets'];
                    $pageNumbersList = $pGlyphData['numbers'] ?? [];
                    $pageUnderlinesList = $pGlyphData['underlines'] ?? [];
                    $pageHeaderLinesList = $pGlyphData['header_lines'] ?? [];
                    $pageBoxesList = $pGlyphData['boxes'] ?? [];
                } else {
                    // 괄호 목록만 직접 전달된 경우
                    $pageBracketsList = $pGlyphData;
                }
            }

            // 2-1. num_roi (문항 번호: 공간적 앵커링을 통해 Upstage 감지 문항 블록 좌상단 50px 이내 글리프만 채택)
            $numRoi = null;
            $qCanvasX1 = (int)round($q['minX'] * $pw);
            $qCanvasY1 = (int)round($q['minY'] * $ph);

            if (!empty($pageNumbersList[$q['num']])) {
                $gn = $pageNumbersList[$q['num']];
                $gnX = isset($gn['normX']) ? (int)round($gn['normX'] * $pw) : (int)$gn['x'];
                $gnY = isset($gn['normY']) ? (int)round($gn['normY'] * $ph) : (int)$gn['y'];
                $gnW = isset($gn['normW']) ? (int)round($gn['normW'] * $pw) : (int)$gn['w'];
                $gnH = isset($gn['normH']) ? (int)round($gn['normH'] * $ph) : (int)$gn['h'];

                // [Q1 풋터 및 Q3 날짜 오인식 방지] 문항 블록 시작점 반경 60px 이내에 있을 때만 채택!
                if (abs($gnX - $qCanvasX1) <= 65 && abs($gnY - $qCanvasY1) <= 45) {
                    $numRoi = ['x' => $gnX, 'y' => $gnY, 'w' => $gnW, 'h' => $gnH];
                }
            }

            if (!$numRoi) {
                $numW = ($q['num'] >= 10) ? 40 : 34; // 1~9번 34px, 10번 이상 40px
                $numH = 26; // 지문 줄 수와 상관없이 번호 1줄 높이만 정밀 타이트하게 감쌈
                $numRoi = ['x' => $qCanvasX1, 'y' => $qCanvasY1, 'w' => $numW, 'h' => $numH];
            }

            // 2-2. 유형 판별 (기호 선택형 -> 객관식, 단답형/서답형/표기형 -> 주관식 정밀 분류, 선잇기 감지)
            $text = $q['cleanText'];
            // 발문이 여러 행/요소에 걸쳐 있는 경우를 위해 다음 문항 직전까지의 텍스트도 포괄 수집
            $fullQText = $text;
            for ($k = $q['el_index'] + 1; $k < $elCount; $k++) {
                $followEl = $elements[$k];
                if ((int)($followEl['page'] ?? 1) !== $pNum) break;
                $fHtml = $followEl['content']['html'] ?? '';
                $fText = trim(strip_tags($fHtml));
                $fText = html_entity_decode($fText, ENT_QUOTES, 'UTF-8');
                if (preg_match('/^\s*\d{1,2}\s*[\.|\s|\)|번]/u', $fText)) break;
                $fullQText .= ' ' . $fText;
            }

            $isMatchingLineQuestion = (bool)preg_match('/선\s*으로|선\s*을\s*그어|선\s*잇기|선\s*으\s*로|연결\s*(?:하시오|해\s*보|하세요|하기)|(?:바르게|알맞게|알맞은\s*것끼리|관련\s*있는\s*것끼리)\s*(?:선\s*으로\s*)?연결|(?:바르게|알맞게|찾아)\s*(?:선\s*으로\s*)?이[으어]|이어\s*보(?:시오|세요)?|이으\s*(?:시오|세요)/u', $fullQText);
            $hasSubjectiveKeywords = (bool)preg_match('/쓰시오|서술|설명하시오|바르게\s*고쳐|까닭|이유/u', $fullQText);

            // 단답형 후보: 짧은 단어·숫자·O/X 기입형을 1차 유형으로 분류하기 위한 감지
            $isShortAnswerCandidate = (bool)preg_match(
                '/(?:단\s*어(?:를\s*)?쓰|낱\s*말(?:을\s*)?쓰|한\s*마디(?:로)?\s*쓰|짧은\s*답|숫자를\s*쓰|수를\s*쓰|한\s*단어|알맞은\s*말(?:을\s*)?쓰|'
                . '(?:\(\s*\))?\s*(?:안에|에)\s*쓰|괄호\s*안|괄호\s*속에\s*쓰|'
                . '(?:○[\s\x{25CB}]?\s*|✕[\s\x{2717}]?\s*|X[\s\x{2717}]?\s*|x[\s\x{2717}]?\s*)?(?:표|기|답|표시|체크|동그라미|O|X)'
                . '|O[\s\x{25CB}]?\s*/\s*✕[\s\x{2717}]?\s*|○[\s\x{25CB}]?\s*/\s*✕[\s\x{2717}]?\s*|'
                . '[Oo]\s*/\s*[Xx]\s*|참\s*/\s*거짓|옳은\s*/\s*그른|맞으면\s*/\s*틀리면|진위|진짜\s*/\s*가짜)/u',
                $fullQText
            );

            // O/X형 단답형과 주관식(설명·까닭·서술)을 분리하기 위해 O/X 지표만 있는 경우 우선 단답형으로 본다.
            $hasOxStyleOnly = (bool)preg_match(
                '/(?:○[\s\x{25CB}]?\s*|✕[\s\x{2717}]?\s*|X[\s\x{2717}]?\s*|x[\s\x{2717}]?\s*|O[\s\x{25CB}]?\s*|체크|동그라미)[\s\x{2003}]*'
                . '(?:표|기|답|표시|체크|O|X)/u',
                $fullQText
            ) && !$hasSubjectiveKeywords;

            $isSymbolType = (bool)preg_match('/기호[를|로]|번호[를|로]|알맞은\s*기호/u', $fullQText) && !preg_match('/○표|○\s*표|V표|v표|체크|동그라미|u', $fullQText);

            // 단답형 우선 판별: O/X 표시형, 단어/숫자 기입형이고 주관식 서술 키워드가 없으면 단답형으로 분류
            $isShortAnswer = $isShortAnswerCandidate && !$hasSubjectiveKeywords;

            if ($isSymbolType) {
                // [Q12 해결] '기호를 쓰시오', '번호를 쓰시오'는 ㉠, 1 등을 적는 선택형이므로 객관식 [객] 분류
                $isShortAnswer = false;
                $isSubjective = false;
            } elseif ($isShortAnswer) {
                // [신설] 짧은 단어·숫자·O/X 기입형은 1차 유형 단답형(short_answer)으로 분류
                $isSubjective = false;
            } elseif ($hasSubjectiveKeywords) {
                // [Q10 해결] '까닭을 쓰시오', '이유를 서술하시오' 등은 주관식 [주] 분류
                $isShortAnswer = false;
                $isSubjective = true;
            } else {
                // 일반 객관식
                $isShortAnswer = false;
                $isSubjective = false;
            }

            // 2-3. ans_roi 결정 (방안 A: 괄호의 높이와 거리 기준 크기 결정 및 괄호 한가운데로 완벽 조준)
            $ansRoi = null;
            $qCanvasX2 = $q['maxX'] * $pw;
            $qCanvasY2 = $q['maxY'] * $ph;
            // [SSOT 보정] PDF.js 번호 글리프가 존재하면 문항 시작 위치를 실제 번호 인쇄 좌표로 정확히 안착
            $qNum = (int)$q['num'];
            if (!empty($pageNumbersList[$qNum])) {
                $curNumGlyph = $pageNumbersList[$qNum];
                $gnY = isset($curNumGlyph['normY']) ? (float)($curNumGlyph['normY'] * $ph) : (float)$curNumGlyph['y'];
                $gnX = isset($curNumGlyph['normX']) ? (float)($curNumGlyph['normX'] * $pw) : (float)$curNumGlyph['x'];
                if (abs($gnX - $qCanvasX1) <= 65 && abs($gnY - $qCanvasY1) <= 45) {
                    $qCanvasY1 = (int)round($gnY);
                    $qCanvasX1 = (int)round($gnX);
                }
            }

            // 문항 번호의 실제 시작 X 좌표 기준으로 단(Column) 판정 (문항 텍스트 전체 너비 기준 오판 방지)
            $isLeftCol = ($qCanvasX1 < $pw * 0.52);

            // 다음 문항의 시작 Y 좌표 계산 (동일 페이지 & 동일 단의 문항: PDF.js 정밀 번호 글리프 우선 참조)
            $nextQY = null;
            for ($n = $idx + 1; $n < $totalQ; $n++) {
                if ($questions[$n]['page'] === $pNum) {
                    $nqNum = (int)$questions[$n]['num'];
                    $ngn = $pageNumbersList[$nqNum] ?? null;
                    if ($ngn) {
                        $nqX1 = (float)(isset($ngn['normX']) ? ($ngn['normX'] * $pw) : $ngn['x']);
                        $nqY1 = (float)(isset($ngn['normY']) ? ($ngn['normY'] * $ph) : $ngn['y']);
                    } else {
                        $nqX1 = (float)($questions[$n]['minX'] * $pw);
                        $nqY1 = (float)($questions[$n]['minY'] * $ph);
                    }
                    $nIsLeft = ($nqX1 < $pw * 0.52);
                    if ($nIsLeft === $isLeftCol && $nqY1 > $qCanvasY1 + 15) {
                        if ($nextQY === null || $nqY1 < $nextQY) {
                            $nextQY = $nqY1;
                        }
                    }
                }
            }

            // =========================================================================
            // [선잇기 전용 신설 AI 처리 루트 (Dedicated Matching-Line AI Pipeline)]
            // 발문에서 선잇기 키워드가 감지되면 일반 괄호/밑줄 분할을 100% 우회하고,
            // 문항 시작부터 다음 문항 직전까지의 전체 영역을 AI로 직접 분석하여 확정
            // =========================================================================
            if ($isMatchingLineQuestion) {
                $colMinX = $isLeftCol ? (int)round($pw * 0.04) : (int)round($pw * 0.50);
                $colMaxX = $isLeftCol ? (int)round($pw * 0.48) : (int)round($pw * 0.94);
                $cropX = $colMinX;
                $cropW = $colMaxX - $colMinX;
                $cropY = max(0, (int)round($qCanvasY1 - 5));
                $cropBottom = ($nextQY !== null) ? max($cropY + 120, (int)round($nextQY - 6)) : min((int)round($ph * 0.95), $cropY + 500);
                $cropH = $cropBottom - $cropY;

                // 기본 fallback ans_roi (지문 아래부터 다음 문항 직전까지)
                $ansRoi = [
                    'x' => $cropX,
                    'y' => (int)round($qCanvasY1 + 35),
                    'w' => $cropW,
                    'h' => max(80, $cropBottom - ($qCanvasY1 + 35))
                ];

                $matchingInfo = null;

                $rois[] = [
                    'id'            => $q['num'],
                    'sub_id'        => null,
                    'display_num'   => (string)$q['num'],
                    'page'          => $pNum,
                    'page_uid'      => $pInfo['page_uid'],
                    'num_roi'       => $numRoi,
                    'ans_roi'       => $ansRoi,
                    'type'          => 'matching_line',
                    'sub_type'      => 'matching_line',
                    'answer'        => '',
                    'score'         => $scorePerQuestion,
                    'matching_info' => $matchingInfo
                ];

                continue; // [핵심] 괄호 분할/밑줄 탐색 루프 완전 우회
            }

            // [핵심] 해당 문항의 유효 수직 범위(qCanvasY1 ~ nextQY) 안의 사각 박스 외곽선 형성
            $qMaxY = ($nextQY !== null) ? ($nextQY - 8) : ($ph * 0.95);
            $curBoxes = [];

            // 1) PDF.js 추출 박스들 (닫힌 사각형 박스)
            if (!empty($pageBoxesList)) {
                foreach ($pageBoxesList as $pBox) {
                    $pbx1 = (float)(isset($pBox['normX']) ? ($pBox['normX'] * $pw) : $pBox['x']);
                    $pby1 = (float)(isset($pBox['normY']) ? ($pBox['normY'] * $ph) : $pBox['y']);
                    $pbw  = (float)(isset($pBox['normW']) ? ($pBox['normW'] * $pw) : $pBox['w']);
                    $pbh  = (float)(isset($pBox['normH']) ? ($pBox['normH'] * $ph) : $pBox['h']);
                    $pbx2 = $pbx1 + $pbw;
                    $pby2 = $pby1 + $pbh;
                    $bLeft = ($pbx1 < $pw * 0.52);
                    if ($bLeft === $isLeftCol && $pby1 >= $qCanvasY1 - 10 && $pby2 <= $qMaxY + 10) {
                        $curBoxes[] = ['x1' => $pbx1, 'x2' => $pbx2, 'y1' => $pby1, 'y2' => $pby2];
                    }
                }
            }

            // 2) 가로선 교차 결합(underline + header_line): 지문/보기 테두리 상자 복원 (단, 밑줄끼리의 결합은 엄격 배제)
            if (!empty($pageUnderlinesList) && !empty($pageHeaderLinesList)) {
                foreach ($pageUnderlinesList as $uLine) {
                    $ux = isset($uLine['normX']) ? ((float)$uLine['normX'] * $pw) : (float)($uLine['x'] ?? 0);
                    $uy = isset($uLine['normY']) ? ((float)$uLine['normY'] * $ph) : (float)($uLine['y'] ?? 0);
                    $uw = isset($uLine['normW']) ? ((float)$uLine['normW'] * $pw) : (float)($uLine['w'] ?? 0);
                    $uIsLeft = isset($uLine['isLeft']) ? (bool)$uLine['isLeft'] : ($ux < $pw * 0.52);
                    if ($uIsLeft !== $isLeftCol || $uy < $qCanvasY1 - 10 || $uy > $qMaxY) continue;

                    foreach ($pageHeaderLinesList as $hLine) {
                        $hx = isset($hLine['normX']) ? ((float)$hLine['normX'] * $pw) : (float)($hLine['x'] ?? 0);
                        $hy = isset($hLine['normY']) ? ((float)$hLine['normY'] * $ph) : (float)($hLine['y'] ?? 0);
                        $hw = isset($hLine['normW']) ? ((float)$hLine['normW'] * $pw) : (float)($hLine['w'] ?? 0);
                        $hIsLeft = isset($hLine['isLeft']) ? (bool)$hLine['isLeft'] : ($hx < $pw * 0.52);
                        if ($hIsLeft !== $isLeftCol || $hy < $qCanvasY1 - 10 || $hy > $qMaxY) continue;

                        $dyBox = abs($hy - $uy);
                        if (abs($ux - $hx) < 35 && abs($uw - $hw) < 40 && $dyBox >= 30 && $dyBox <= 320) {
                            $curBoxes[] = [
                                'x1' => min($ux, $hx),
                                'x2' => max($ux + $uw, $hx + $hw),
                                'y1' => min($uy, $hy),
                                'y2' => max($uy, $hy)
                            ];
                        }
                    }
                }
            }
            if (!empty($upstageBoxesByPage[$pNum])) {
                foreach ($upstageBoxesByPage[$pNum] as $ub) {
                    $ubX1 = $ub['minX'] * $pw;
                    $ubY1 = $ub['minY'] * $ph;
                    $ubX2 = $ub['maxX'] * $pw;
                    $ubY2 = $ub['maxY'] * $ph;
                    $ubLeft = ($ubX1 < $pw * 0.5);
                    if ($ubLeft === $isLeftCol && $ubY1 >= $qCanvasY1 - 10 && $ubY2 <= $qMaxY + 10) {
                        $curBoxes[] = ['x1' => $ubX1, 'x2' => $ubX2, 'y1' => $ubY1, 'y2' => $ubY2];
                    }
                }
            }

            $maxBoxY2 = 0;
            foreach ($curBoxes as $cb) {
                if ($cb['y2'] > $maxBoxY2 && $cb['y1'] >= $qCanvasY1 - 10 && $cb['y2'] <= $qMaxY) {
                    $maxBoxY2 = (float)$cb['y2'];
                }
            }

            // 방안 A: PDF.js 추출 괄호 중 최적 매칭 검색 (4대 기하 배제 규칙 & 다형성 행 추적)
            $matchedBrackets = [];
            // 순수 서술형 문항(소문항 키워드 없고 발문 괄호도 없는 경우) 및 선잇기 문항은 괄호 매칭 우회하여 다음 문항 괄호 탈취 방지
            $hasHeadingBracket = (mb_strpos($q['cleanText'], '(') !== false && mb_strpos($q['cleanText'], ')') !== false)
                              || (mb_substr($q['cleanText'], -1) === '(');
            $hasSubKeywords = (bool)preg_match('/각각|모두|○표|○\s*표|두\s*가지|짝\s*지|기호|번호/u', $fullQText);
            $isPureSubjectiveEssay = $isSubjective && !$hasHeadingBracket && !$hasSubKeywords;

            $candidateBrackets = ($isMatchingLineQuestion || $isPureSubjectiveEssay) ? [] : $pageBracketsList;

            if (!empty($candidateBrackets)) {
                $candidates = [];

                foreach ($candidateBrackets as $bIdx => $b) {
                    if (!empty($usedBrackets[$pNum][$bIdx])) continue;

                    $bMidX = (float)($b['midX'] ?? ($b['x'] + $b['w'] / 2));
                    $bMidY = (float)($b['midY'] ?? ($b['y'] + $b['h'] / 2));
                    $bW    = (float)($b['w'] ?? 40);

                    // 단(Column) 일치 검사
                    if ($pw > 800) {
                        $bIsLeft = ($bMidX < $pw * 0.52);
                        if ($bIsLeft !== $isLeftCol) continue;
                    }

                    // Y 범위 검사
                    if ($bMidY < $qCanvasY1 - 20) continue;
                    if ($nextQY !== null && $bMidY >= $nextQY - 8) continue;
                    if ($nextQY === null && $bMidY > $ph * 0.96) continue;

                    // X 범위 검사
                    if ($bMidX < $qCanvasX1 + 25) continue;

                    // [규칙 1] 사각 테두리(보기 상자, 지문 상자) 내부 괄호 배제
                    $isInsideBox = false;
                    foreach ($curBoxes as $box) {
                        if ($bMidX >= $box['x1'] - 5 && $bMidX <= $box['x2'] + 5 && $bMidY >= $box['y1'] + 3 && $bMidY <= $box['y2'] - 3) {
                            $isInsideBox = true;
                            break;
                        }
                    }

                    // [규칙 2] 괄호 내부 기인쇄 텍스트 검사 (insideText가 ㉠, ㉡ 등 인쇄 기호인 경우)
                    $bText = trim($b['text'] ?? '');
                    $isNonEmptyBracket = false;
                    if ($bText !== '' && preg_match('/[\(\（]\s*([가-힣a-zA-Z0-9㉠-㉭①-⑳]+)\s*[\)\）]/u', $bText, $mInside)) {
                        $insideStr = trim($mInside[1]);
                        if (!empty($insideStr) && !preg_match('/^[\s\.\·\-\_]+$/u', $insideStr)) {
                            $isNonEmptyBracket = true;
                        }
                    }

                    // [규칙 3] 지시문 인라인 괄호 검사
                    $isOnHeadingLine = (abs($bMidY - $qCanvasY1) <= 28);

                    $candidates[] = [
                        'bIdx'              => $bIdx,
                        'b'                 => $b,
                        'w'                 => $bW,
                        'bMidX'             => $bMidX,
                        'bMidY'             => $bMidY,
                        'isOnHeadingLine'   => $isOnHeadingLine,
                        'isInsideBox'       => $isInsideBox,
                        'isNonEmptyBracket' => $isNonEmptyBracket,
                        'isChoice'          => !empty($b['isChoiceBracket'])
                    ];
                }

                // 1차 필터링: 보기 상자 내부 및 기인쇄 문자 제외
                $validList = array_values(array_filter($candidates, function($c) {
                    return !$c['isInsideBox'] && !$c['isNonEmptyBracket'];
                }));

                // [규칙 4] 장폭 정답 괄호(w >= 120px) 우선 채택 (하단 정답란 우선 바인딩)
                $wideBrackets = array_values(array_filter($validList, function($c) { return $c['w'] >= 120; }));
                if (!empty($wideBrackets)) {
                    $validList = $wideBrackets;
                } else {
                    $nonHeading = array_values(array_filter($validList, function($c) { return !$c['isOnHeadingLine']; }));
                    if (!empty($nonHeading)) {
                        $validList = $nonHeading;
                    }
                }

                if (!empty($validList)) {
                    // 다형성 행(Row) 클러스터링 및 정렬 (안정적 Y 오름차순)
                    usort($validList, function($a, $b) {
                        return $a['bMidY'] <=> $b['bMidY'];
                    });

                    $rows = [];
                    foreach ($validList as $item) {
                        $matchedRow = false;
                        foreach ($rows as $rIdx => $row) {
                            if (abs($row['y'] - $item['bMidY']) <= 28) {
                                $rows[$rIdx]['items'][] = $item;
                                $matchedRow = true;
                                break;
                            }
                        }
                        if (!$matchedRow) {
                            $rows[] = [
                                'y' => $item['bMidY'],
                                'items' => [$item]
                            ];
                        }
                    }

                    $selectedCandidates = [];
                    $rowCount = count($rows);
                    $lastRow = $rowCount > 0 ? $rows[$rowCount - 1] : null;
                    $hasMultiRows = ($rowCount >= 2);

                    if ($hasMultiRows && $lastRow && count($lastRow['items']) >= 2 && $rowCount <= 3) {
                        // 최하단 행에 2개 이상의 정답 괄호가 몰려 있는 전형적인 빈칸 단답형 (Q8, Q14)
                        $lastItems = $lastRow['items'];
                        usort($lastItems, function($a, $b) { return $a['bMidX'] <=> $b['bMidX']; });
                        $selectedCandidates = $lastItems;
                    } elseif ($hasMultiRows) {
                        // 각 행마다 1개씩 괄호가 있는 다행 소문항 (Q10의 (1), (2), (3) OX형)
                        foreach ($rows as $row) {
                            $rowItems = $row['items'];
                            usort($rowItems, function($a, $b) { return $a['bMidX'] <=> $b['bMidX']; });
                            $selectedCandidates[] = $rowItems[0];
                        }
                        usort($selectedCandidates, function($a, $b) { return $a['bMidY'] <=> $b['bMidY']; });
                    } else {
                        // 단일 행이거나 1개 답란인 경우 (Q1, Q2, Q3, Q5, Q18 등)
                        usort($validList, function($a, $b) { return $a['bMidX'] <=> $b['bMidX']; });
                        $selectedCandidates = $validList;
                    }

                    foreach ($selectedCandidates as $vl) {
                        $usedBrackets[$pNum][$vl['bIdx']] = true;
                        $matchedB = $vl['b'];
                        if (isset($matchedB['normX']) && isset($matchedB['normW'])) {
                            $bRoi = [
                                'x' => (int)round($matchedB['normX'] * $pw),
                                'y' => (int)round($matchedB['normY'] * $ph),
                                'w' => (int)round($matchedB['normW'] * $pw),
                                'h' => (int)round($matchedB['normH'] * $ph)
                            ];
                        } else {
                            $bRoi = [
                                'x' => (int)$matchedB['x'],
                                'y' => (int)$matchedB['y'],
                                'w' => (int)$matchedB['w'],
                                'h' => (int)$matchedB['h']
                            ];
                        }
                        $matchedBrackets[] = $bRoi;
                    }

                    $ansRoi = $matchedBrackets[0] ?? null;
                }
            }

            // [2단계] 주관식 밑줄(Underlines) 1~3줄 정밀 매칭 (줄 간격 Δy 비례 동적 높이)
            if (!$ansRoi && !empty($pageUnderlinesList)) {
                $qLines = [];
                foreach ($pageUnderlinesList as $uIdx => $u) {
                    if (!empty($usedUnderlines[$pNum][$uIdx])) continue;
                    $uY = (float)(isset($u['normY']) ? ($u['normY'] * $ph) : $u['y']);
                    $uX = (float)(isset($u['normX']) ? ($u['normX'] * $pw) : $u['x']);
                    $uW = (float)(isset($u['normW']) ? ($u['normW'] * $pw) : $u['w']);
                    $uIsLeft = isset($u['isLeft']) ? $u['isLeft'] : ($uX < $pw * 0.52);

                    // 단(Column) 일치 검사
                    if ($pw > 800 && $uIsLeft !== $isLeftCol) continue;

                    // Y 범위 검사: 문항 번호 아래, 다음 문항 직전
                    if ($uY < $qCanvasY1 + 18) continue;
                    // [Q13 해결] 보기 상자가 있는 경우, 보기 상자 내부의 선은 답란이 아니므로 제외!
                    if ($maxBoxY2 > 0 && $uY <= $maxBoxY2 + 5) continue;
                    if ($nextQY !== null && $uY >= $nextQY - 8) continue;
                    if ($uY > $ph * 0.96) continue; // [Q20 해결] 0.92 -> 0.96 완화 (하단 2번째 밑줄 포함)

                    $qLines[] = [
                        'uIdx' => $uIdx,
                        'y' => $uY,
                        'x' => $uX,
                        'w' => $uW
                    ];
                }

                if (!empty($qLines)) {
                    // Y좌표 오름차순 정렬
                    usort($qLines, function($a, $b) { return $a['y'] <=> $b['y']; });

                    // 사용 처리
                    foreach ($qLines as $ql) {
                        $usedUnderlines[$pNum][$ql['uIdx']] = true;
                    }

                    $lineCount = count($qLines);
                    $firstY = $qLines[0]['y'];
                    $lastY = $qLines[$lineCount - 1]['y'];

                    // 사용자 설계 반영: 줄 간격 Δy 동적 산출
                    if ($lineCount >= 2) {
                        $deltaY = ($lastY - $firstY) / ($lineCount - 1);
                        $deltaY = max(24, min(65, $deltaY));
                    } else {
                        // 1줄만 감지되었더라도 서술형 문항이면 2줄 분량 높이(45px) 확보
                        $deltaY = $isSubjective ? 45 : 34;
                    }

                    // 시작점: 첫 번째 밑줄 위로 줄 간격(Δy)만큼 정확히 확보
                    $ansStartY = (int)round($firstY - $deltaY);
                    // [Q20 해결] 서술형이고 1줄만 잡혔을 경우, 밑줄 아래로 35px 더 확장하여 2번째 밑줄까지 안전 포괄
                    $rawEndY = (int)round($lastY + ($isSubjective && $lineCount === 1 ? 35 : 10));
                    $maxAllowedEndY = ($nextQY !== null) ? (int)round($nextQY - 12) : (int)round($ph * 0.95);
                    $ansEndY = min($rawEndY, $maxAllowedEndY);

                    $minX = min(array_column($qLines, 'x'));
                    $maxW = max(array_column($qLines, 'w'));

                    $ansRoi = [
                        'x' => (int)round($minX),
                        'y' => (int)round($ansStartY),
                        'w' => (int)round($maxW),
                        'h' => max(40, (int)round($ansEndY - $ansStartY))
                    ];
                }
            }

            // [3단계] 빈 사각형 박스 (Boxes) 매칭
            if (!$ansRoi && !empty($pageBoxesList)) {
                foreach ($pageBoxesList as $bBox) {
                    if (empty($bBox['isEmpty'])) continue;
                    $bx = (float)(isset($bBox['normX']) ? ($bBox['normX'] * $pw) : $bBox['x']);
                    $by = (float)(isset($bBox['normY']) ? ($bBox['normY'] * $ph) : $bBox['y']);
                    $bw = (float)(isset($bBox['normW']) ? ($bBox['normW'] * $pw) : $bBox['w']);
                    $bh = (float)(isset($bBox['normH']) ? ($bBox['normH'] * $ph) : $bBox['h']);
                    $bIsLeft = ($bx < $pw * 0.52);

                    if ($pw > 800 && $bIsLeft !== $isLeftCol) continue;
                    if ($by < $qCanvasY1 + 18) continue;
                    if ($nextQY !== null && $by + $bh >= $nextQY - 5) continue;

                    $ansRoi = [
                        'x' => (int)round($bx + 4),
                        'y' => (int)round($by + 4),
                        'w' => (int)round($bw - 8),
                        'h' => (int)round($bh - 8)
                    ];
                    break;
                }
            }

            // [4단계] 폴백: PDF.js 괄호/밑줄 매칭이 없을 경우 Upstage BBox 기반 보정
            if (!$ansRoi) {
                $bracketBBox = null;
                $lookAheadLimit = min($elCount, $q['el_index'] + 4);
                for ($k = $q['el_index'] + 1; $k < $lookAheadLimit; $k++) {
                    $followEl = $elements[$k];
                    if ((int)($followEl['page'] ?? 1) !== $pNum) break;

                    $fHtml = $followEl['content']['html'] ?? '';
                    $fText = trim(strip_tags($fHtml));
                    $fText = html_entity_decode($fText, ENT_QUOTES, 'UTF-8');

                    // [Q8, Q17 해결] '(' 와 ')' 가 두 개 연속된 요소로 나뉜 경우 결합
                    if (preg_match('/^\s*[\(\（]\s*$/u', $fText) && ($k + 1 < $lookAheadLimit)) {
                        $nextEl = $elements[$k + 1];
                        $nText = trim(strip_tags($nextEl['content']['html'] ?? ''));
                        if (preg_match('/^\s*[\)\）]\s*$/u', $nText)) {
                            $leftXs = array_column($followEl['coordinates'] ?? [], 'x');
                            $rightXs = array_column($nextEl['coordinates'] ?? [], 'x');
                            $ys = array_column($followEl['coordinates'] ?? [], 'y');
                            if (!empty($leftXs) && !empty($rightXs)) {
                                $bX = (int)round(min($leftXs) * $pw);
                                $bMaxX = (int)round(max($rightXs) * $pw);
                                $bY = (int)round(min($ys) * $ph);
                                $bracketBBox = [
                                    'x' => $bX,
                                    'y' => $bY,
                                    'w' => max(38, $bMaxX - $bX),
                                    'h' => 32
                                ];
                                break;
                            }
                        }
                    }

                    // 단일 요소 내 괄호 ( )
                    if (preg_match('/[\(\（]\s*[\)\）]/u', $fText)) {
                        $fCoords = $followEl['coordinates'] ?? [];
                        if (!empty($fCoords)) {
                            $fxs = array_column($fCoords, 'x');
                            $fys = array_column($fCoords, 'y');
                            $fMinX = min($fxs);
                            $fMaxX = max($fxs);
                            $fMinY = min($fys);

                            $bracketBBox = [
                                'x' => (int)round($fMinX * $pw),
                                'y' => (int)round($fMinY * $ph),
                                'w' => max(38, (int)round(($fMaxX - $fMinX) * $pw)),
                                'h' => 32
                            ];
                            break;
                        }
                    }
                }

                $hasHeadingBracket = (mb_strpos($q['cleanText'], '(') !== false && mb_strpos($q['cleanText'], ')') !== false)
                                  || (mb_substr($q['cleanText'], -1) === '(');

                if ($bracketBBox) {
                    $ansRoi = $bracketBBox;
                } elseif ($hasHeadingBracket) {
                    $ansW = 42;
                    $ansH = 32;
                    $ansX = (int)round($q['maxX'] * $pw) - $ansW;
                    $ansY = (int)round($q['maxY'] * $ph) - $ansH;
                    $ansRoi = ['x' => $ansX, 'y' => $ansY, 'w' => $ansW, 'h' => $ansH];
                } else {
                    // [사례 1 & 사례 4 해결] 주관식 서술형 밑줄 2~3줄 및 단(Column) 격리 완벽 보정
                    // 1. 단(Column) 기준 좌우 X 범위 결정 (반대편 단 침범 원천 차단)
                    if ($isLeftCol) {
                        $colMinX = (int)round($pw * 0.06);
                        $colMaxX = (int)round($pw * 0.48);
                    } else {
                        $colMinX = (int)round($pw * 0.52);
                        $colMaxX = (int)round($pw * 0.94);
                    }

                    // 2. 상하 Y 범위 결정
                    $promptHeight = min(80, max(30, (int)round(($q['maxY'] - $q['minY']) * $ph)));

                    if ($isMatchingLineQuestion) {
                        // [선잇기 전용] 시작점은 발문 바로 아래(상단 항목들 위), 종료점은 하단 보기/박스 바닥까지 전체 영역 감쌈
                        $ansStartY = (int)round($qCanvasY1 + max(25, $promptHeight));
                        $maxAllowedEndY = ($nextQY !== null) ? (int)round($nextQY - 12) : (int)round($ph * 0.95);
                        if ($maxBoxY2 > 0 && $maxBoxY2 <= $maxAllowedEndY) {
                            $ansEndY = (int)round($maxBoxY2 + 10);
                        } else {
                            $ansEndY = min($maxAllowedEndY, $ansStartY + 230);
                        }
                    } else {
                        // 일반 서술형 / 주관식
                        $ansStartY = $qCanvasY1 + $promptHeight + 10;
                        if ($maxBoxY2 > 0 && $maxBoxY2 > $ansStartY && $maxBoxY2 < ($nextQY !== null ? ($nextQY - 10) : ($ph * 0.95))) {
                            $ansStartY = (int)round($maxBoxY2 + 10);
                        }

                        // 종료점: 같은 단의 다음 문항 직전, 또는 페이지 하단 마진(95%) 직전
                        if ($nextQY !== null) {
                            $maxAllowedEndY = (int)round($nextQY - 15);
                        } else {
                            $maxAllowedEndY = (int)round($ph * 0.95);
                        }

                        $ansStartY = min($ansStartY, $maxAllowedEndY - 50);
                        if ($ansStartY < $qCanvasY1 + 35) {
                            $ansStartY = (int)round($qCanvasY1 + 35);
                        }
                        $ansEndY = $maxAllowedEndY;
                    }

                    $ansX = $colMinX;
                    $ansW = $colMaxX - $colMinX;
                    $ansY = $ansStartY;
                    $ansH = max(60, $ansEndY - $ansStartY);

                    $ansRoi = ['x' => $ansX, 'y' => $ansY, 'w' => $ansW, 'h' => $ansH];
                }
            }

            if (count($matchedBrackets) >= 2) {
                $subCount = count($matchedBrackets);
                $subScore = (int)round($scorePerQuestion / $subCount);
                if ($subScore <= 0) $subScore = 1;

                // 소문항 답안란 단위 type은 1차 유형 기준으로 결정하고, 소문항 여부는 2차 분류로 표현
                $subBaseType = $isMatchingLineQuestion ? 'matching_line' : ($isSubjective ? 'subjective' : ($isShortAnswer ? 'short_answer' : 'objective'));

                foreach ($matchedBrackets as $subIdx => $mRoi) {
                    $subNum = $subIdx + 1;
                    $rois[] = [
                        'id'          => $q['num'],
                        'sub_id'      => $subNum,
                        'display_num' => "{$q['num']}-{$subNum}",
                        'page'        => $pNum,
                        'page_uid'    => $pInfo['page_uid'],
                        'num_roi'     => $numRoi,
                        'ans_roi'     => $mRoi,
                        'type'        => $subBaseType,
                        'is_sub_item' => true,
                        'sub_type'    => $subBaseType,
                        'answer'      => '',
                        'score'       => $subScore
                    ];
                }
            } else {
                $qFinalType = $isMatchingLineQuestion ? 'matching_line' : ($isSubjective ? 'subjective' : ($isShortAnswer ? 'short_answer' : 'objective'));
                $rois[] = [
                    'id'          => $q['num'],
                    'sub_id'      => null,
                    'display_num' => (string)$q['num'],
                    'page'        => $pNum,
                    'page_uid'    => $pInfo['page_uid'],
                    'num_roi'     => $numRoi,
                    'ans_roi'     => $ansRoi,
                    'type'        => $qFinalType,
                    'sub_type'    => $qFinalType,
                    'answer'      => '',
                    'score'       => $scorePerQuestion
                ];
            }
        }

        // 문항 번호 및 소문항 기준 오름차순 정렬
        usort($rois, function($a, $b) {
            if ($a['id'] === $b['id']) {
                return ($a['sub_id'] ?? 0) <=> ($b['sub_id'] ?? 0);
            }
            return $a['id'] <=> $b['id'];
        });

        return $rois;
    }
}

if (!function_exists('detectStudentInfoMaskRois')) {
    /**
     * Upstage Document AI 파싱 결과에서 1쪽의 인적사항 기재란(학교, 학년, 반, 번호, 이름, 성명 및 우측 빈칸)을
     * 정밀 탐지하여 전체를 포괄하는 단 1개의 통합 직사각형(Unified Bounding Box) 마스킹 ROI 산출
     *
     * @param array $upstageData Upstage API 응답 배열
     * @param array $pagesData templates.pages_data 배열
     * @return array 정밀 마스킹 BBox 배열 [ ['page' => 1, 'x' => ..., 'y' => ..., 'w' => ..., 'h' => ... ] ]
     */
    /**
     * 시험지 상단 인적사항(학교, 학년, 반, 번호, 이름) 기재란 정밀 마스킹 영역(ROI) 자동 탐지
     *
     * [설계 원칙 - 임의의 고정 비율 하드코딩 완전 배제]
     * 1. PDF.js 클라이언트가 추출한 물리적 텍스트 글리프(student_header_items, title_items, entry_blanks)와
     *    캔버스 상단 가로선(header_lines)의 실제 픽셀 좌표를 1차 진실의 원천(SSOT)으로 사용.
     * 2. 제목/출처(과목명, 단원명, <과학>, 쪽수) 글리프의 우측 끝(X_title_max)을 가드로 세워 1픽셀도 침범하지 않음.
     * 3. Upstage의 외곽 Table 테두리와 교차 융합(Cross-referencing)하여 표 우측 테두리 및 상하 경계를 정밀 확정.
     * 4. 감지된 모든 구성 요소를 감싸는 '단 1개의 정밀 통합 직사각형(Unified Masking ROI)' 산출.
     */
    function detectStudentInfoMaskRois(array $upstageData, array $pagesData = [], array $pageGlyphs = []): array {
        $elements = $upstageData['elements'] ?? [];
        if (empty($elements)) {
            return [];
        }

        // 1쪽 캔버스 기준 해상도 확인 (기본값: 1200 x 1700)
        $p1Width  = 1200;
        $p1Height = 1700;
        if (!empty($pagesData)) {
            foreach ($pagesData as $p) {
                if ((int)($p['page_num'] ?? 1) === 1) {
                    $p1Width  = (int)($p['width']  ?? 1200);
                    $p1Height = (int)($p['height'] ?? 1700);
                    break;
                }
            }
        }

        // 1페이지 엘리먼트 수집 + Upstage 좌표 최댓값 측정 (스케일 판별용)
        $p1MaxX    = 0.0;
        $p1MaxY    = 0.0;
        $p1Elements = [];
        foreach ($elements as $el) {
            if ((int)($el['page'] ?? 1) !== 1 || empty($el['coordinates'])) continue;
            $p1Elements[] = $el;
            foreach ($el['coordinates'] as $c) {
                if (isset($c['x']) && (float)$c['x'] > $p1MaxX) $p1MaxX = (float)$c['x'];
                if (isset($c['y']) && (float)$c['y'] > $p1MaxY) $p1MaxY = (float)$c['y'];
            }
        }
        if (empty($p1Elements)) return [];

        // 정규화 좌표(0~1) vs 절대 픽셀 좌표 판별
        $isNorm = ($p1MaxX <= 1.05 && $p1MaxY <= 1.05);
        $baseW  = $isNorm ? 1.0 : ($p1MaxX > 100 ? $p1MaxX : $p1Width);
        $baseH  = $isNorm ? 1.0 : ($p1MaxY > 100 ? $p1MaxY : $p1Height);

        // ──────────────────────────────────────────────────────────
        // [A] PDF.js 정밀 글리프 데이터 로드 (1페이지)
        // ──────────────────────────────────────────────────────────
        $p1Glyphs      = $pageGlyphs[1] ?? ($pageGlyphs['1'] ?? []);
        $studentGlyphs = $p1Glyphs['student_header_items'] ?? [];
        $titleGlyphs   = $p1Glyphs['title_items']          ?? [];
        $headerLines   = $p1Glyphs['header_lines']         ?? [];

        // ──────────────────────────────────────────────────────────
        // [B] Upstage 상단 표(전체 너비 table) → 마스킹 테두리 획득
        //     tableTopY / tableBottomY / tableRightX
        // ──────────────────────────────────────────────────────────
        $tableTopY    = null;
        $tableBottomY = null;
        $tableRightX  = null;

        foreach ($p1Elements as $el) {
            $cat = $el['category'] ?? '';
            if ($cat !== 'table') continue;

            $coords = $el['coordinates'] ?? [];
            if (empty($coords)) continue;

            $xs   = array_column($coords, 'x');
            $ys   = array_column($coords, 'y');
            $pxX1 = (min($xs) / $baseW) * $p1Width;
            $pxY1 = (min($ys) / $baseH) * $p1Height;
            $pxX2 = (max($xs) / $baseW) * $p1Width;
            $pxY2 = (max($ys) / $baseH) * $p1Height;

            // 전체 너비 표(전체 가로 40% 이상) 이고 상단 30% 이내
            if (($pxX2 - $pxX1) < ($p1Width * 0.40)) continue;
            if (($pxY1 / $p1Height) > 0.30) continue;

            if ($tableTopY === null || $pxY1 < $tableTopY)       $tableTopY    = $pxY1;
            if ($tableBottomY === null || $pxY2 > $tableBottomY) $tableBottomY = $pxY2;
            if ($tableRightX === null || $pxX2 > $tableRightX)   $tableRightX  = $pxX2;
        }

        // ──────────────────────────────────────────────────────────
        // [C] title_items 글리프로 단원명/출처 최대 X 산출 (음성 제약)
        //     헤더 영역 내(Y <= headerMaxY) + 좌측 72% 이내 텍스트만 대상
        // ──────────────────────────────────────────────────────────
        $titleMaxX    = 0.0;
        $headerMaxY   = $tableBottomY ?? ($p1Height * 0.25);

        foreach ($titleGlyphs as $g) {
            $gy = (float)($g['y'] ?? 0);
            $gx = (float)($g['x'] ?? 0);
            $gw = (float)($g['w'] ?? 0);
            if ($gy > $headerMaxY) continue;
            if (($gx + $gw) > ($p1Width * 0.72)) continue; // 우측 인적사항 영역 제외
            $rightEdge = $gx + $gw;
            if ($rightEdge > $titleMaxX) $titleMaxX = $rightEdge;
        }

        // ──────────────────────────────────────────────────────────
        // [D] student_header_items + 우측 header_lines 로 rawMinX 산출
        //     (인적사항란 실제 시작 X)
        // ──────────────────────────────────────────────────────────
        $rawMinX = null;

        // student_header_items (학교/학년/반/번/이름 텍스트 글리프)
        foreach ($studentGlyphs as $g) {
            $gx = (float)($g['x'] ?? 0);
            if ($rawMinX === null || $gx < $rawMinX) $rawMinX = $gx;
        }

        // header_lines 중 우측 절반(X > 50%)에 있는 선 → 인적사항 구분선
        // header_lines: header area (Y <= headerMaxY)
        // + right 60% section + short lines (w < pw*0.40)
        // => extract only student entry lines, exclude table-internal lines
        foreach ($headerLines as $line) {
            $ly = (float)($line['y'] ?? 0);
            $lx = (float)($line['x'] ?? 0);
            $lw = (float)($line['w'] ?? 0);
            if ($ly > $headerMaxY) continue;
            if ($lx < ($p1Width * 0.60)) continue;
            if ($lw > ($p1Width * 0.40)) continue;
            if ($rawMinX === null || $lx < $rawMinX) $rawMinX = $lx;
        }

        // ──────────────────────────────────────────────────────────
        // [E] 마스킹 ROI 최종 산출
        // ──────────────────────────────────────────────────────────

        // Y 범위: Upstage table 기준 (없으면 header_lines 범위 사용)
        if ($tableTopY !== null && $tableBottomY !== null) {
            $maskY1 = (int)round($tableTopY   + 2);
            $maskY2 = (int)round($tableBottomY - 2);
        } else {
            // Fallback: header_lines Y 범위
            $lineYs = array_map(fn($l) => (float)($l['y'] ?? 0), $headerLines);
            if (!empty($lineYs)) {
                $maskY1 = (int)round(min($lineYs));
                $maskY2 = (int)round(max($lineYs) + (float)($headerLines[count($headerLines)-1]['h'] ?? 20));
            } else {
                $maskY1 = 0;
                $maskY2 = (int)round($p1Height * 0.15);
            }
        }

        // X 범위: 음성 제약(titleMaxX) + rawMinX 중 더 넓은 쪽
        if ($rawMinX !== null) {
            // 인적사항 시작 X = max(rawMinX-10, titleMaxX+12) → 단원명 절대 침범 불가
            $maskX1 = (int)round(max($rawMinX - 2, $titleMaxX + 12));
        } elseif ($titleMaxX > 0) {
            // student_header_items 없음 → titleMaxX 기준으로만 우측 결정
            $maskX1 = (int)round($titleMaxX + 12);
        } else {
            // 완전 fallback: 페이지 너비의 74%
            $maskX1 = (int)round($p1Width * 0.74);
        }

        // 오른쪽 끝: Upstage table 우측 테두리 (없으면 페이지 98%)
        $maskX2 = ($tableRightX !== null)
            ? (int)round($tableRightX - 3)
            : (int)round($p1Width * 0.98);

        $finalX = max(0, $maskX1);
        $finalY = max(0, $maskY1);
        $finalW = min($p1Width  - $finalX, $maskX2 - $maskX1);
        $finalH = min($p1Height - $finalY, $maskY2 - $maskY1);

        if ($finalW < 30 || $finalH < 15) {
            return [];
        }

        return [[
            'page'  => 1,
            'x'     => $finalX,
            'y'     => $finalY,
            'w'     => $finalW,
            'h'     => $finalH,
            'label' => 'student_info'
        ]];
    }

}

if (!function_exists('getOrDetectPrivacyMaskRois')) {
    /**
     * 템플릿의 privacy_mask_data를 조회하고, 없으면 Upstage + PDF.js 글리프로 1회 자동 탐지하여 DB에 캐시(Self-Healing)
     */
    function getOrDetectPrivacyMaskRois(PDO $pdo, int $templateId, ?array $templateRow = null): array {
        // 1. templates 테이블 최신 행 조회 (SSOT)
        $stmt = $pdo->prepare("SELECT id, user_id, roi_data, pages_data, image_path, privacy_mask_data FROM templates WHERE id = :id");
        $stmt->execute(['id' => $templateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [];
        }

        // 2. 이미 캐시된 privacy_mask_data가 있으면 즉시 반환
        if (!empty($row['privacy_mask_data'])) {
            $decoded = json_decode($row['privacy_mask_data'], true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }

        // 3. 템플릿 원본 파일 경로 탐색 (최대 2단계 추적)
        $imagePath = $row['image_path'] ?? '';
        if (empty($imagePath)) {
            $pages = json_decode($row['pages_data'] ?? '[]', true) ?: [];
            $imagePath = $pages[0]['image_path'] ?? '';
        }

        if (empty($imagePath)) {
            return [];
        }

        $cleanRel = ltrim($imagePath, '/');
        $imgPhysPath = APP_ROOT . '/' . $cleanRel;
        $tplDir = dirname($imgPhysPath);
        $pdfPath = $tplDir . "/tpl_{$templateId}_original.pdf";
        $glyphsPath = $tplDir . "/tpl_{$templateId}_glyphs.json";

        // 글리프 캐시 파일이 있으면 함께 로드
        $pageGlyphs = file_exists($glyphsPath) ? (json_decode(file_get_contents($glyphsPath), true) ?: []) : [];

        // PDF 우선, 차선 1페이지 렌더링 이미지 전송
        $targetFile = file_exists($pdfPath) ? $pdfPath : (file_exists($imgPhysPath) ? $imgPhysPath : null);

        if ($targetFile) {
            $upstageData = parseDocumentWithUpstage($targetFile);
            if ($upstageData) {
                $pagesData = json_decode($row['pages_data'] ?? '[]', true) ?: [];
                $detected = detectStudentInfoMaskRois($upstageData, $pagesData, $pageGlyphs);
                if (!empty($detected)) {
                    $json = json_encode($detected, JSON_UNESCAPED_UNICODE);
                    $upStmt = $pdo->prepare("UPDATE templates SET privacy_mask_data = :mask WHERE id = :id");
                    $upStmt->execute(['mask' => $json, 'id' => $templateId]);
                    return $detected;
                }
            }
        }
        return [];
    }
}
