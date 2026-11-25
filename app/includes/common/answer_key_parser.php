<?php
/**
 * includes/common/answer_key_parser.php
 * 이원목적분류표 및 정답표 자동 파싱 & 시험지 ROI 역교정 바인딩 엔진
 *
 * 지원 기능:
 * 1. PDF / 이미지 이원목적분류표 자동 파싱 (Upstage Document Parse API 활용)
 * 2. 2D Grid 기반 HTML 표 분석 (rowspan, colspan 완벽 대응)
 * 3. 텍스트 / 표 복사-붙여넣기 데이터 지능형 파싱 (TSV, CSV, 줄바꿈 텍스트)
 * 4. 원문자(①~⑩), 한글 기호(㉠~㉤), 소문항 분할 답안, 서술형 "아래 참조" 모범답안 자동 정규화
 * 5. Phase 1 시험지 문항 ROI 배열(rois)과 정답·배점·채점기준 1:1 역교정 바인딩
 */

require_once dirname(__DIR__, 2) . '/config/env.php';

if (!function_exists('parseAnswerKeyPdf')) {
    /**
     * PDF 또는 이미지 파일 형태의 이원목적분류표 파싱
     *
     * @param string $filePath 로컬 파일 절대 경로
     * @return array 파싱된 문항별 정답 및 배점 데이터 연관 배열 [ q_num => [...] ]
     */
    function parseAnswerKeyPdf(string $filePath): array {
        if (!file_exists($filePath)) {
            error_log("[AnswerKeyParser] 파일이 존재하지 않습니다: {$filePath}");
            return [];
        }

        require_once __DIR__ . '/upstage.php';
        $upstageData = parseDocumentWithUpstage($filePath);
        if (!$upstageData || empty($upstageData['elements'])) {
            error_log("[AnswerKeyParser] Upstage 파싱 결과가 유효하지 않습니다.");
            return [];
        }

        return parseAnswerKeyFromUpstageElements($upstageData['elements']);
    }
}

if (!function_exists('parseAnswerKeyFromUpstageElements')) {
    /**
     * Upstage Document Parse 결과의 elements 배열에서 이원목적분류표 추출
     *
     * @param array $elements
     * @return array
     */
    function parseAnswerKeyFromUpstageElements(array $elements): array {
        $tableHtml = '';
        $descriptiveTexts = [];

        foreach ($elements as $el) {
            $cat = $el['category'] ?? '';
            $html = $el['content']['html'] ?? '';

            if ($cat === 'table' && empty($tableHtml)) {
                $tableHtml = $html;
            } elseif ($cat === 'paragraph' || $cat === 'list' || $cat === 'table') {
                $plain = trim(strip_tags($html));
                $plain = html_entity_decode($plain, ENT_QUOTES, 'UTF-8');
                // 서술형 모범 답안 단락 탐색 (예: "7. 지구본에... 등 11. 바닷물은...")
                if (preg_match('/\b\d{1,2}\s*[\.|\)]\s*[가-힣]/u', $plain)) {
                    $descriptiveTexts[] = $plain;
                }
            }
        }

        if (empty($tableHtml)) {
            // 표가 없는 경우 전체 텍스트 기반 폴백 파싱
            $allText = '';
            foreach ($elements as $el) {
                $allText .= strip_tags($el['content']['html'] ?? '') . "\n";
            }
            return parseAnswerKeyText($allText);
        }

        return parseAnswerKeyFromHtmlTable($tableHtml, implode("\n", $descriptiveTexts));
    }
}

if (!function_exists('parseAnswerKeyFromHtmlTable')) {
    /**
     * HTML <table> 문자열을 2D Grid로 분석하여 정형화된 문항 정답 배열 추출 (rowspan/colspan 완벽 지원)
     *
     * @param string $tableHtml
     * @param string $additionalDescriptiveText 표 외부 또는 하단 서술형 답안 텍스트
     * @return array
     */
    function parseAnswerKeyFromHtmlTable(string $tableHtml, string $additionalDescriptiveText = ''): array {
        // 1. 모든 <tr> 추출
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tableHtml, $trMatches);
        if (empty($trMatches[1])) {
            return [];
        }

        // 2. 2D 격자(Grid) 구축하여 rowspan, colspan 완벽 전개
        $grid = [];
        $descriptiveBlockFromTable = '';

        foreach ($trMatches[1] as $rIdx => $trContent) {
            preg_match_all('/<t([dh])([^>]*)>(.*?)<\/t\1>/is', $trContent, $cellMatches, PREG_SET_ORDER);
            if (empty($cellMatches)) continue;

            if (!isset($grid[$rIdx])) {
                $grid[$rIdx] = [];
            }

            $cCursor = 0;

            foreach ($cellMatches as $cm) {
                $attrs = $cm[2];
                $content = $cm[3];

                // 현재 행에서 빈 슬롯 찾기
                while (isset($grid[$rIdx][$cCursor]) && $grid[$rIdx][$cCursor] !== null) {
                    $cCursor++;
                }

                $rowspan = 1;
                $colspan = 1;
                if (preg_match('/rowspan=[\'"]?(\d+)/i', $attrs, $mR)) {
                    $rowspan = (int)$mR[1];
                }
                if (preg_match('/colspan=[\'"]?(\d+)/i', $attrs, $mC)) {
                    $colspan = (int)$mC[1];
                }

                $cellText = strip_tags($content);
                $cellText = html_entity_decode($cellText, ENT_QUOTES, 'UTF-8');
                $cellText = trim(preg_replace('/\s+/u', ' ', $cellText));

                // 서술형 모범 답안 단락이 단일 셀로 전체를 차지한 경우 수집
                if ($colspan >= 4 && preg_match('/\b\d{1,2}\s*[\.|\)]\s*[가-힣]/u', $cellText)) {
                    $descriptiveBlockFromTable .= " " . $cellText;
                }

                for ($dr = 0; $dr < $rowspan; $dr++) {
                    $targetR = $rIdx + $dr;
                    if (!isset($grid[$targetR])) {
                        $grid[$targetR] = [];
                    }
                    for ($dc = 0; $dc < $colspan; $dc++) {
                        $targetC = $cCursor + $dc;
                        $grid[$targetR][$targetC] = $cellText;
                    }
                }

                $cCursor += $colspan;
            }
        }

        if (empty($grid)) {
            return [];
        }

        // 3. 열별 헤더 텍스트 조합 및 열 매핑 식별
        $maxCols = 0;
        foreach ($grid as $r) {
            $maxCols = max($maxCols, count($r));
        }

        $colHeaders = [];
        for ($c = 0; $c < $maxCols; $c++) {
            $h0 = $grid[0][$c] ?? '';
            $h1 = $grid[1][$c] ?? '';
            // 두 행의 헤더가 같으면(rowspan) 중복 제거
            if ($h0 === $h1) {
                $colHeaders[$c] = $h0;
            } else {
                $colHeaders[$c] = trim($h0 . ' ' . $h1);
            }
        }

        $colMap = [
            'num'      => 0,
            'answer'   => 2,
            'score'    => 3,
            'criteria' => 4
        ];

        foreach ($colHeaders as $cIdx => $hText) {
            if (preg_match('/(문항\s*번호|문\s*항|번\s*호)/u', $hText)) {
                $colMap['num'] = $cIdx;
            } elseif (preg_match('/(정\s*답|모범\s*답안|답\s*안)/u', $hText)) {
                $colMap['answer'] = $cIdx;
            } elseif (preg_match('/(배\s*점|점\s*수)/u', $hText) && !preg_match('/(채점\s*기준)/u', $hText)) {
                $colMap['score'] = $cIdx;
            } elseif (preg_match('/(채점\s*기준|유의점|루브릭)/u', $hText)) {
                $colMap['criteria'] = $cIdx;
            }
        }

        // 4. 서술형 모범 답안 맵 추출
        $fullDescriptiveText = trim($descriptiveBlockFromTable . "\n" . $additionalDescriptiveText);
        $descriptiveMap = extractDescriptiveAnswersMap($fullDescriptiveText);

        $results = [];

        // 5. 데이터 행 추출 (문항 번호가 1~100 숫자인 행 탐색)
        for ($r = 0; $r < count($grid); $r++) {
            $row = $grid[$r];
            $numCell = trim($row[$colMap['num']] ?? ($row[0] ?? ''));

            if (!preg_match('/^\s*(\d{1,2})\s*$/u', $numCell, $mNum)) {
                continue;
            }

            $qNum = (int)$mNum[1];
            if ($qNum < 1 || $qNum > 100) continue;

            $rawAnswer   = trim($row[$colMap['answer']] ?? ($row[2] ?? ''));
            $rawScore    = trim($row[$colMap['score']] ?? ($row[3] ?? '5'));
            $rawCriteria = trim($row[$colMap['criteria']] ?? ($row[4] ?? ''));

            $score = 5.0;
            if (preg_match('/(\d+(\.\d+)?)/', $rawScore, $mScore)) {
                $score = (float)$mScore[1];
            }

            $parsedItem = normalizeAnswerEntry($qNum, $rawAnswer, $score, $rawCriteria, $descriptiveMap);
            $results[$qNum] = $parsedItem;
        }

        return $results;
    }
}

if (!function_exists('parseAnswerKeyText')) {
    /**
     * 텍스트 또는 표 복사-붙여넣기 문자열 파싱 (TSV, 줄바꿈 등)
     *
     * @param string $rawText
     * @return array
     */
    function parseAnswerKeyText(string $rawText): array {
        $lines = preg_split('/\r\n|\r|\n/', trim($rawText));
        if (empty($lines)) return [];

        $descriptiveMap = extractDescriptiveAnswersMap($rawText);
        $results = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;

            $cells = preg_split('/\t+|\s{2,}/', $line);
            if (count($cells) < 2) continue;

            $qNum = null;
            $numIdx = -1;
            foreach ($cells as $idx => $cell) {
                if (preg_match('/^(\d{1,2})[\.|\)]?$/', trim($cell), $m)) {
                    $qNum = (int)$m[1];
                    $numIdx = $idx;
                    break;
                }
            }

            if ($qNum === null || $qNum < 1 || $qNum > 100) continue;

            $rawAnswer = $cells[$numIdx + 1] ?? '';
            $score = 5.0;
            $rawCriteria = '';

            for ($c = $numIdx + 2; $c < count($cells); $c++) {
                $val = trim($cells[$c]);
                if (is_numeric($val) && $score == 5.0) {
                    $score = (float)$val;
                } elseif (mb_strlen($val) > 3) {
                    $rawCriteria .= ($rawCriteria ? ' ' : '') . $val;
                }
            }

            $results[$qNum] = normalizeAnswerEntry($qNum, $rawAnswer, $score, $rawCriteria, $descriptiveMap);
        }

        return $results;
    }
}

if (!function_exists('extractDescriptiveAnswersMap')) {
    /**
     * 표 하단 또는 별도 영역의 서술형 모범 답안 단락에서 문항별 모범답안 텍스트 추출
     * 예: "7. 지구본에 육지 붙임딱지와... 등 11. 바닷물은 소금을 포함해... 등 19. 우리나라의 갯벌은..."
     *
     * @param string $text
     * @return array [ q_num => "모범답안 텍스트" ]
     */
    function extractDescriptiveAnswersMap(string $text): array {
        $map = [];
        if (!trim($text)) return $map;

        // 패턴: "7. 모범답안..." 또는 "7) 모범답안..."
        preg_match_all('/(?:^|\s)(\d{1,2})\s*[\.|\)]\s*(.*?)(?=(?:\s+\d{1,2}\s*[\.|\)]\s*)|$)/us', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $qNum = (int)$m[1];
            $ans = trim($m[2]);
            $ans = preg_replace('/\s*등[\.]?$/u', '', $ans);
            // 괄호 잔여물 제거
            $ans = preg_replace('/\s*[\(\（]\s*$/u', '', $ans);
            if ($qNum >= 1 && $qNum <= 100 && mb_strlen($ans) >= 5) {
                $map[$qNum] = $ans;
            }
        }

        return $map;
    }
}

if (!function_exists('normalizeAnswerEntry')) {
    /**
     * 단일 문항의 정답 문자열, 배점, 채점 기준을 분석하여 4대 표준 유형 및 정규화 데이터 생성
     *
     * @param int $qNum
     * @param string $rawAnswer
     * @param float $score
     * @param string $criteria
     * @param array $descriptiveMap
     * @return array
     */
    function normalizeAnswerEntry(int $qNum, string $rawAnswer, float $score, string $criteria, array $descriptiveMap = []): array {
        $rawAnswerTrim = trim($rawAnswer);

        // 원문자 매핑 테이블 (①~⑳ -> 1~20)
        $circleMap = [
            '①'=>'1', '②'=>'2', '③'=>'3', '④'=>'4', '⑤'=>'5',
            '⑥'=>'6', '⑦'=>'7', '⑧'=>'8', '⑨'=>'9', '⑩'=>'10',
            '⑪'=>'11', '⑫'=>'12', '⑬'=>'13', '⑭'=>'14', '⑮'=>'15',
            '⑯'=>'16', '⑰'=>'17', '⑱'=>'18', '⑲'=>'19', '⑳'=>'20'
        ];

        // 1. 서술형 "아래 참조" 감지 (명시적으로 '참조'가 있을 때만 descriptiveMap 우선 연동)
        $isBelowRef = (mb_strpos($rawAnswerTrim, '아래') !== false && mb_strpos($rawAnswerTrim, '참조') !== false)
                   || (mb_strpos($rawAnswerTrim, '해설') !== false && mb_strpos($rawAnswerTrim, '참조') !== false)
                   || (mb_strpos($rawAnswerTrim, '별도') !== false && mb_strpos($rawAnswerTrim, '채점') !== false);

        if ($isBelowRef) {
            $descAnswer = $descriptiveMap[$qNum] ?? $rawAnswerTrim;
            return [
                'q_num'                => $qNum,
                'raw_answer'           => $rawAnswerTrim,
                'normalized_answer'    => $descAnswer,
                'type'                 => 'subjective',
                'sub_type'             => null,
                'score'                => $score,
                'criteria'             => $criteria,
                'is_sub_item'          => false,
                'has_partial_score'    => false,
                'sub_answers'          => [],
                'suggested_sub_scores' => []
            ];
        }

        // 2-0. 선 잇기 (matching_line) 정밀 감지
        // 조건: 1) 명시적 연결 기호(-, ➔, →, :, ~)가 있거나
        //       2) 채점기준/발문에 '선으로 연결', '바르게 연결', '선잇기' 키워드가 명시된 경우만 인정!
        $isLineKeyword = (mb_strpos($criteria, '선으로 연결') !== false)
                      || (mb_strpos($criteria, '바르게 연결') !== false)
                      || (mb_strpos($criteria, '연결하시오') !== false)
                      || (mb_strpos($criteria, '선잇기') !== false)
                      || (mb_strpos($rawAnswerTrim, '➔') !== false)
                      || (mb_strpos($rawAnswerTrim, '→') !== false);

        $hasExplicitConnector = (bool)preg_match('/[\-➔→=~:>]/u', $rawAnswerTrim);

        $isMatchingLineMatch = false;
        $mPairs = [];

        if ($hasExplicitConnector) {
            // 명시적 연결 기호가 있는 경우 (예: 1-ㄷ, 2-ㄴ 또는 (1)-㉢, (2)-㉡ 또는 1 ➔ ㉢)
            if (preg_match_all('/[\(\（]?(\d+)[\)\）]?\s*[\-➔→=~:>]\s*([㉠-㉭ㄱ-ㅎ가-힣\w]+)/u', $rawAnswerTrim, $mPairs) && count($mPairs[1]) >= 2) {
                $isMatchingLineMatch = true;
            }
        } elseif ($isLineKeyword) {
            // 발문 키워드가 있는 경우 (예: (1) ㉠ (2) ㉢ (3) ㉡)
            if (preg_match_all('/[\(\（]?(\d+)[\)\）]?\s*[\-➔→=~:]*\s*([㉠-㉭ㄱ-ㅎ])/u', $rawAnswerTrim, $mPairs) && count($mPairs[1]) >= 2) {
                $isMatchingLineMatch = true;
            }
        } elseif (preg_match_all('/[\(\（](\d+)[\)\）]\s*([㉠-㉭ㄱ-ㅎ])(?!\s*[,，])/u', $rawAnswerTrim, $mPairs) && count($mPairs[1]) >= 2) {
            // 연결 기호 없이 (1) ㉢ (2) ㉡ (3) ㉠ 처럼 각 소문항이 1:1로 고유 기호에 단일 대응되는 선잇기 정답 감지
            // 쉼표로 복수 기호를 고르는 소문항(예: (1) ㉠, ㉡)은 엄격히 배제
            $hasCommaInside = (bool)preg_match('/[\(\（]\d+[\)\）][^(\(（]*[,，]/u', $rawAnswerTrim);
            if (!$hasCommaInside) {
                $uniqueTargets = array_unique($mPairs[2]);
                if (count($uniqueTargets) === count($mPairs[2])) {
                    $isMatchingLineMatch = true;
                }
            }
        }

        if ($isMatchingLineMatch) {
            $pairs = [];
            $subAnswers = [];
            $suggestedScores = [];
            $subCount = count($mPairs[1]);
            $baseScore = round($score / max(1, $subCount), 1);

            foreach ($mPairs[1] as $k => $numStr) {
                $idx = (int)$numStr;
                $val = trim($mPairs[2][$k]);
                $pairs[] = [
                    'from' => '(' . $idx . ')',
                    'to'   => $val
                ];
                $subAnswers[$idx] = $val;
                $suggestedScores[$idx] = ($k == $subCount - 1) ? round($score - $baseScore * ($subCount - 1), 1) : $baseScore;
            }

            $hasPartial = false;
            if (preg_match('/(부분\s*점수|각\s*\d+점|개당\s*\d+점|한\s*개당)/u', $criteria)) {
                $hasPartial = true;
            }

            return [
                'q_num'                => $qNum,
                'raw_answer'           => $rawAnswerTrim,
                'normalized_answer'    => $rawAnswerTrim,
                'type'                 => 'matching_line',
                'sub_type'             => 'matching_line',
                'score'                => $score,
                'criteria'             => $criteria,
                'is_sub_item'          => false,
                'has_partial_score'    => $hasPartial,
                'sub_answers'          => $subAnswers,
                'suggested_sub_scores' => $suggestedScores,
                'pairs'                => $pairs
            ];
        }

        // 2. 소문항 분할 정답 감지
        // 패턴 A: ㉠ 바다 ㉡ 육지 (Q8, Q14 등)
        if (preg_match_all('/([㉠-㉭])\s*([^㉠-㉭,]+)/u', $rawAnswerTrim, $mSym) && count($mSym[1]) >= 2) {
            $subAnswers = [];
            foreach ($mSym[2] as $sIdx => $val) {
                $subAnswers[$sIdx + 1] = trim($val);
            }
            return buildSubItemEntry($qNum, $rawAnswerTrim, $score, $criteria, $subAnswers, 'subjective');
        }

        // 패턴 B: (1) 육지의 물 (2) 바닷물  또는 (1) ○ (2) ○ (3) ✕  또는 (1) ㉡ (2) ㉠ (Q9, Q10, Q12)
        if (preg_match_all('/[\(\（](\d+)[\)\）]\s*([^(\(（)]+)/u', $rawAnswerTrim, $mParen) && count($mParen[1]) >= 2) {
            $subAnswers = [];
            $allOx = true;
            $allSym = true;
            $allShortAnswerCandidate = true;

            foreach ($mParen[1] as $k => $subNumStr) {
                $subIdx = (int)$subNumStr;
                $val = trim($mParen[2][$k]);
                // OX 기호 정규화
                if (preg_match('/^[○O0o]$/u', $val)) {
                    $val = '○';
                } elseif (preg_match('/^[Xx✕✗]$/u', $val)) {
                    $val = '✕';
                } else {
                    $allOx = false;
                }
                if (!preg_match('/^[㉠-㉭]$/u', $val)) {
                    $allSym = false;
                }
                $subAnswers[$subIdx] = $val;
            }

            // 단답형 후보 판별: OX도 기호도 아닌 경우, 짧은 단어·숫자 형태인지 확인
            if (!$allOx && !$allSym) {
                foreach ($subAnswers as $ans) {
                    $wordCount = str_word_count(mb_convert_encoding($ans, 'UTF-8', 'UTF-8'));
                    $isShort = (mb_strlen($ans) <= 20 && $wordCount <= 2);
                    if (!$isShort) {
                        $allShortAnswerCandidate = false;
                        break;
                    }
                }
            }

            $isSubjectiveCriteria = (bool)preg_match('/쓰시오|서술|설명|까닭|이유/u', $criteria);
            $subType = $allOx ? 'short_ox'
                : ($allSym ? 'objective'
                : ($allShortAnswerCandidate && !$isSubjectiveCriteria ? 'short_answer' : 'subjective'));

            return buildSubItemEntry($qNum, $rawAnswerTrim, $score, $criteria, $subAnswers, $subType);
        }

        // 3. 복수 객관식 선택 (예: ㉠, ㉢ 또는 ①, ③)
        if (strpos($rawAnswerTrim, ',') !== false) {
            $parts = array_map('trim', explode(',', $rawAnswerTrim));
            $normParts = [];
            foreach ($parts as $p) {
                $normParts[] = $circleMap[$p] ?? $p;
            }
            return [
                'q_num'                => $qNum,
                'raw_answer'           => $rawAnswerTrim,
                'normalized_answer'    => implode(', ', $normParts),
                'type'                 => 'objective',
                'sub_type'             => 'objective',
                'score'                => $score,
                'criteria'             => $criteria,
                'is_sub_item'          => false,
                'has_partial_score'    => false,
                'sub_answers'          => [],
                'suggested_sub_scores' => []
            ];
        }

        // 4. 단일 원문자 객관식 (①~⑳ -> 1~20)
        if (isset($circleMap[$rawAnswerTrim])) {
            return [
                'q_num'                => $qNum,
                'raw_answer'           => $rawAnswerTrim,
                'normalized_answer'    => $circleMap[$rawAnswerTrim],
                'type'                 => 'objective',
                'sub_type'             => 'objective',
                'score'                => $score,
                'criteria'             => $criteria,
                'is_sub_item'          => false,
                'has_partial_score'    => false,
                'sub_answers'          => [],
                'suggested_sub_scores' => []
            ];
        }

        // 5. 기호형 객관식 (㉠~㉭)
        if (preg_match('/^[㉠-㉭]$/u', $rawAnswerTrim)) {
            return [
                'q_num'                => $qNum,
                'raw_answer'           => $rawAnswerTrim,
                'normalized_answer'    => $rawAnswerTrim,
                'type'                 => 'objective',
                'sub_type'             => 'objective',
                'score'                => $score,
                'criteria'             => $criteria,
                'is_sub_item'          => false,
                'has_partial_score'    => false,
                'sub_answers'          => [],
                'suggested_sub_scores' => []
            ];
        }

        // 6. 단답형 단어 (예: "가치")
        return [
            'q_num'                => $qNum,
            'raw_answer'           => $rawAnswerTrim,
            'normalized_answer'    => $rawAnswerTrim,
            'type'                 => 'short_answer',
            'sub_type'             => null,
            'score'                => $score,
            'criteria'             => $criteria,
            'is_sub_item'          => false,
            'has_partial_score'    => false,
            'sub_answers'          => [],
            'suggested_sub_scores' => []
        ];
    }
}

if (!function_exists('buildSubItemEntry')) {
    /**
     * 소문항 데이터 헬퍼 빌더
     */
    function buildSubItemEntry(int $qNum, string $rawAnswer, float $score, string $criteria, array $subAnswers, string $type): array {
        $subCount = count($subAnswers);
        $hasPartial = false;
        $suggestedScores = [];

        // 채점 기준에서 부분점수 언급 탐색
        // 예: "한 개만 정답이면 2점", "한 개만 1점, 두 개만 3점"
        if (preg_match('/한\s*개만\s*(?:정답이면)?\s*(\d+)점/u', $criteria, $mPart)) {
            $hasPartial = true;
            $firstSubScore = (float)$mPart[1];
            if ($subCount == 2) {
                $suggestedScores[1] = $firstSubScore;
                $suggestedScores[2] = max(1.0, $score - $firstSubScore);
            } elseif ($subCount == 3) {
                $secondSubTotal = $score;
                if (preg_match('/두\s*개만\s*(?:정답이면)?\s*(\d+)점/u', $criteria, $mPart2)) {
                    $secondSubTotal = (float)$mPart2[1];
                }
                $suggestedScores[1] = $firstSubScore;
                $suggestedScores[2] = max(1.0, $secondSubTotal - $firstSubScore);
                $suggestedScores[3] = max(1.0, $score - $secondSubTotal);
            }
        }

        // 기본 균등 분할
        if (empty($suggestedScores)) {
            $baseScore = round($score / $subCount, 1);
            foreach ($subAnswers as $sIdx => $val) {
                $suggestedScores[$sIdx] = ($sIdx == $subCount) ? round($score - $baseScore * ($subCount - 1), 1) : $baseScore;
            }
        }

        return [
            'q_num'                => $qNum,
            'raw_answer'           => $rawAnswer,
            'normalized_answer'    => $rawAnswer,
            'type'                 => $type,
            'sub_type'             => $type,
            'score'                => $score,
            'criteria'             => $criteria,
            'is_sub_item'          => true,
            'has_partial_score'    => $hasPartial,
            'sub_answers'          => $subAnswers,
            'suggested_sub_scores' => $suggestedScores
        ];
    }
}

if (!function_exists('bindAnswerKeyToRois')) {
    /**
     * Phase 1의 시험지 ROI 배열과 파싱된 정답표를 결합(역교정 바인딩)
     *
     * @param array $rois templates.roi_data 배열
     * @param array $parsedAnswers parseAnswerKeyPdf 또는 parseAnswerKeyText 결과 [ qNum => [...] ]
     * @return array 정답·배점·루브릭이 주입된 새로운 rois 배열
     */
    function bindAnswerKeyToRois(array $rois, array $parsedAnswers): array {
        if (empty($parsedAnswers)) {
            return $rois;
        }

        // [선잇기 문항 병합] 정답표에서 matching_line으로 지정되었으나 기존 ROI에 복수 sub_item으로 나뉘어 있는 경우 단일 문항으로 통합
        $groupedRois = [];
        foreach ($rois as $r) {
            $qNum = (int)($r['id'] ?? 0);
            $groupedRois[$qNum][] = $r;
        }

        $collapsedRois = [];
        foreach ($groupedRois as $qNum => $qGroup) {
            $ansInfo = $parsedAnswers[$qNum] ?? null;
            if ($ansInfo && !($ansInfo['is_sub_item'] ?? true) && count($qGroup) > 1) {
                // [수정 B] matching_line뿐 아니라 정답표가 단일 문항(is_sub_item=false)인데
                // 시험지 ROI가 여러 개로 쪼개진 모든 유형에 대해 첫 대표 ROI만 남김
                $first = $qGroup[0];
                if (($ansInfo['type'] ?? '') === 'matching_line') {
                    // 선잇기 문항만 다이어그램 통합 BBox 생성
                    $minX = 999999; $minY = 999999; $maxX = 0; $maxY = 0;
                    foreach ($qGroup as $item) {
                        if (!empty($item['ans_roi'])) {
                            $minX = min($minX, $item['ans_roi']['x']);
                            $minY = min($minY, $item['ans_roi']['y']);
                            $maxX = max($maxX, $item['ans_roi']['x'] + $item['ans_roi']['w']);
                            $maxY = max($maxY, $item['ans_roi']['y'] + $item['ans_roi']['h']);
                        }
                    }
                    $mergedAnsRoi = ($maxX > $minX && $maxY > $minY) 
                        ? ['x' => $minX, 'y' => $minY, 'w' => $maxX - $minX, 'h' => $maxY - $minY]
                        : ($first['ans_roi'] ?? ['x' => 100, 'y' => 100, 'w' => 300, 'h' => 200]);
                    $first['ans_roi'] = $mergedAnsRoi;
                } else {
                    // 객관식/단답형 등 단일 문항은 첫 번째 대표 정답 괄호(답란) 유지 (다중 괄호 BBox 무차별 결합 방지)
                    $first['ans_roi'] = $first['ans_roi'] ?? ['x' => 100, 'y' => 100, 'w' => 300, 'h' => 200];
                }

                $first['sub_id'] = null;
                $first['display_num'] = (string)$qNum;
                $first['type'] = $ansInfo['type'] ?? $first['type'];
                $first['sub_type'] = $ansInfo['type'] ?? $first['sub_type'];
                unset($first['ans_rois']);
                $collapsedRois[] = $first;
            } else {
                foreach ($qGroup as $item) {
                    $collapsedRois[] = $item;
                }
            }
        }
        $rois = $collapsedRois;

        $boundRois = [];

        foreach ($rois as $r) {
            $qNum = (int)($r['id'] ?? 0);
            if (!isset($parsedAnswers[$qNum])) {
                $boundRois[] = $r;
                continue;
            }

            $ansInfo = $parsedAnswers[$qNum];
            $subId = $r['sub_id'] ?? null;

            // 1. 소문항 ROI 바인딩 (Q8-1, Q8-2, Q10-1 등)
            if ($subId !== null && !empty($ansInfo['is_sub_item']) && isset($ansInfo['sub_answers'][$subId])) {
                $r['answer'] = $ansInfo['sub_answers'][$subId];
                if (isset($ansInfo['suggested_sub_scores'][$subId])) {
                    $r['score'] = $ansInfo['suggested_sub_scores'][$subId];
                }
                $r['has_partial_score'] = $ansInfo['has_partial_score'] ?? false;
                $r['criteria'] = $ansInfo['criteria'] ?? '';
                $r['type'] = 'sub_item';
                $r['sub_type'] = $ansInfo['sub_type'];
            } else {
                // 2. 단일 문항 바인딩 (객관식, 서술형, 단답/OX, 선잇기)
                $r['answer'] = $ansInfo['normalized_answer'] ?? ($ansInfo['answer'] ?? '');
                $r['score'] = (int)round($ansInfo['score']);
                $r['criteria'] = $ansInfo['criteria'] ?? '';
                $r['has_partial_score'] = $ansInfo['has_partial_score'] ?? false;
                if (!empty($ansInfo['suggested_sub_scores'])) {
                    $r['sub_scores'] = $ansInfo['suggested_sub_scores'];
                }

                if ($ansInfo['type'] === 'matching_line') {
                    $r['type'] = 'matching_line';
                    $r['sub_type'] = null;
                    if (!empty($ansInfo['pairs'])) {
                        $r['pairs'] = $ansInfo['pairs'];
                    }
                } elseif ($ansInfo['type'] === 'subjective') {
                    $r['type'] = 'subjective';
                    $r['sub_type'] = null;
                } elseif ($ansInfo['type'] === 'short_ox') {
                    $r['type'] = 'short_answer';
                    $r['sub_type'] = null;
                } elseif ($ansInfo['type'] === 'objective') {
                    $r['type'] = 'objective';
                    $r['sub_type'] = null;
                } elseif ($ansInfo['type'] === 'short_answer') {
                    $r['type'] = 'short_answer';
                    $r['sub_type'] = null;
                }
            }

            $boundRois[] = $r;
        }

        return $boundRois;
    }
}
