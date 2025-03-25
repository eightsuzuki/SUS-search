<?php
// LocalAlignment.php

/**
 * LocalAlignmentConfig クラス
 * 各種スコア設定と文字ごとのペナルティ設定を保持します。
 */
class LocalAlignmentConfig {
    public $defaultMatchScore;
    public $defaultUnmatchPenalty;
    public $defaultGapPenalty;
    public $penaltyMap;

    public function __construct($defaultMatchScore, $defaultUnmatchPenalty, $defaultGapPenalty, $penaltyMap = array()) {
        $this->defaultMatchScore = $defaultMatchScore;
        $this->defaultUnmatchPenalty = $defaultUnmatchPenalty;
        $this->defaultGapPenalty = $defaultGapPenalty;
        $this->penaltyMap = $penaltyMap;
    }
}

/**
 * newLocalAlignmentConfig
 * コンフィグのインスタンスを作成します。
 */
function newLocalAlignmentConfig($defaultMatchScore, $defaultUnmatchPenalty, $defaultGapPenalty, $penaltyMap) {
    return new LocalAlignmentConfig($defaultMatchScore, $defaultUnmatchPenalty, $defaultGapPenalty, $penaltyMap);
}

/**
 * getLocalAlignment
 *
 * 2つの文字列 $s と $t の局所アライメント（共通部分抽出）を行い、
 * その結果を返します。
 */
function getLocalAlignment($s, $t, $config = null) {
    $matchScore = 1;
    if ($config !== null) {
        $matchScore = $config->defaultMatchScore;
    }
    $unmatchPenalty = 0;
    if ($config !== null) {
        $unmatchPenalty = $config->defaultUnmatchPenalty;
    }
    $getPenalty = function($r) use ($config) {
        if ($config === null) {
            return 0;
        }
        if (isset($config->penaltyMap[$r])) {
            return $config->penaltyMap[$r];
        }
        return $config->defaultGapPenalty;
    };

    // マルチバイト対応の文字配列に変換
    $sArr = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    $tArr = preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY);
    $sLen = count($sArr);
    $tLen = count($tArr);

    // DPテーブルの初期化
    $dp = array();
    for ($i = 0; $i <= $sLen; $i++) {
        $dp[$i] = array_fill(0, $tLen + 1, 0);
    }

    $highestScore = 0;
    $highestI = 0;
    $highestJ = 0;
    for ($i = 1; $i <= $sLen; $i++) {
        for ($j = 1; $j <= $tLen; $j++) {
            if ($sArr[$i - 1] === $tArr[$j - 1]) {
                $diagonalScore = $dp[$i - 1][$j - 1] + $matchScore;
            } else {
                $diagonalScore = $dp[$i - 1][$j - 1] - $unmatchPenalty;
            }
            $scoreFromDiagonal = $diagonalScore;
            $scoreFromUp = $dp[$i - 1][$j] - $getPenalty($sArr[$i - 1]);
            $scoreFromLeft = $dp[$i][$j - 1] - $getPenalty($tArr[$j - 1]);
            $dp[$i][$j] = max(0, $scoreFromDiagonal, $scoreFromUp, $scoreFromLeft);
            if ($dp[$i][$j] > $highestScore) {
                $highestScore = $dp[$i][$j];
                $highestI = $i;
                $highestJ = $j;
            }
        }
    }

    $localAlignment = array();
    backtrack($sArr, $tArr, $dp, $highestI, $highestJ, $localAlignment);
    return implode('', $localAlignment);
}

/**
 * backtrack
 * DPテーブルから局所整列結果を再構築します。
 */
function backtrack($sArr, $tArr, $dp, $i, $j, &$lcs) {
    if ($i == 0 || $j == 0 || $dp[$i][$j] == 0) {
        return;
    }
    if ($sArr[$i - 1] === $tArr[$j - 1]) {
        backtrack($sArr, $tArr, $dp, $i - 1, $j - 1, $lcs);
        $lcs[] = $sArr[$i - 1];
    } else {
        if ($dp[$i - 1][$j] >= $dp[$i][$j - 1]) {
            backtrack($sArr, $tArr, $dp, $i - 1, $j, $lcs);
        } else {
            backtrack($sArr, $tArr, $dp, $i, $j - 1, $lcs);
        }
    }
}