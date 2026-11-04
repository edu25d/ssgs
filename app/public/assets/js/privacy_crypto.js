/**
 * public/assets/js/privacy_crypto.js
 * 쌤글라스(SsamGlass) 제로-지식 학생 프라이버시 클라이언트 암호화 모듈
 * 
 * 학급 고유 솔트(classSalt)와 학생 실명(studentName)을 결합하여
 * 32자리 표준 가명 식별자(student_hash)를 생성합니다.
 */

// 순수 자바스크립트 SHA-256 구현 (비보안 HTTP 또는 특수 환경 폴백용)
function fallbackSha256(ascii) {
    function rightRotate(value, amount) {
        return (value >>> amount) | (value << (32 - amount));
    }
    
    var mathPow = Math.pow;
    var maxWord = mathPow(2, 32);
    var lengthProperty = 'length';
    var i, j;
    var result = '';

    var words = [];
    var asciiBitLength = ascii[lengthProperty] * 8;
    
    var hash = [];
    var k = [];
    var primeCounter = 0;

    var isComposite = {};
    for (var candidate = 2; primeCounter < 64; candidate++) {
        if (!isComposite[candidate]) {
            for (i = 0; i < 313; i += candidate) {
                isComposite[i] = candidate;
            }
            hash[primeCounter] = (mathPow(candidate, .5) * maxWord) | 0;
            k[primeCounter++] = (mathPow(candidate, 1/3) * maxWord) | 0;
        }
    }
    
    ascii += '\x80';
    while (ascii[lengthProperty] % 64 - 56) ascii += '\x00';
    for (i = 0; i < ascii[lengthProperty]; i++) {
        j = ascii.charCodeAt(i);
        if (j >> 8) return ''; // UTF-8 비호환 시 빈 문자열
        words[i >> 2] |= j << ((3 - i) % 4) * 8;
    }
    words[words[lengthProperty]] = ((asciiBitLength / maxWord) | 0);
    words[words[lengthProperty]] = (asciiBitLength | 0);
    
    for (j = 0; j < words[lengthProperty];) {
        var w = words.slice(j, j += 16);
        var oldHash = hash;
        hash = hash.slice(0, 8);
        
        for (i = 0; i < 64; i++) {
            var i2 = i + j;
            var w15 = w[i - 15], w2 = w[i - 2];

            var a = hash[0], e = hash[4];
            var temp1 = hash[7]
                + (rightRotate(e, 6) ^ rightRotate(e, 11) ^ rightRotate(e, 25))
                + ((e & hash[5]) ^ ((~e) & hash[6]))
                + k[i]
                + (w[i] = (i < 16) ? w[i] : (
                        w[i - 16]
                        + (rightRotate(w15, 7) ^ rightRotate(w15, 18) ^ (w15 >>> 3))
                        + w[i - 7]
                        + (rightRotate(w2, 17) ^ rightRotate(w2, 19) ^ (w2 >>> 10))
                    ) | 0
                );
            var temp2 = (rightRotate(a, 2) ^ rightRotate(a, 13) ^ rightRotate(a, 22))
                + ((a & hash[1]) ^ (a & hash[2]) ^ (hash[1] & hash[2]));
            
            hash = [(temp1 + temp2) | 0].concat(hash);
            hash[4] = (hash[4] + temp1) | 0;
        }
        
        for (i = 0; i < 8; i++) {
            hash[i] = (hash[i] + oldHash[i]) | 0;
        }
    }
    
    for (i = 0; i < 8; i++) {
        for (j = 3; j >= 0; j--) {
            var b = (hash[i] >> (8 * j)) & 255;
            result += (b < 16 ? '0' : '') + b.toString(16);
        }
    }
    return result;
}

/**
 * 학급 솔트와 학생 이름을 결합하여 32자리 student_hash 반환
 * @param {string} classSalt - 학급 고유 32자리 난수 솔트
 * @param {string} studentName - 학생 실명
 * @returns {Promise<string>} 32자리 16진수 해시 문자열
 */
async function generateStudentHash(classSalt, studentName) {
    const cleanSalt = (classSalt || '').trim();
    const cleanName = (studentName || '').trim();
    const rawString = cleanSalt + "_" + cleanName;

    // 1. Web Crypto API 우선 사용 (HTTPS / Localhost)
    if (window.crypto && window.crypto.subtle && window.crypto.subtle.digest) {
        try {
            const encoder = new TextEncoder();
            const data = encoder.encode(rawString);
            const hashBuffer = await window.crypto.subtle.digest('SHA-256', data);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            const hex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
            return hex.substring(0, 32);
        } catch (e) {
            console.warn('[PrivacyCrypto] Web Crypto 실패, 폴백 사용:', e);
        }
    }

    // 2. Fallback SHA-256
    const utf8Str = unescape(encodeURIComponent(rawString));
    const fullHex = fallbackSha256(utf8Str);
    return fullHex.substring(0, 32);
}

// 전역 바인딩 (window 객체)
if (typeof window !== 'undefined') {
    window.generateStudentHash = generateStudentHash;
}
