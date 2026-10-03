(() => {
    'use strict';
    // QR Model 2, version 6, error correction L, byte mode, mask 0.
    // Two data blocks of 68 bytes, each with 18 Reed-Solomon check bytes.
    // Fixed capacity keeps this small local encoder auditable; overlong URLs fail visibly.
    const SIZE = 41;
    const multiply = (x, y) => {
        let result = 0;
        for (let i = 7; i >= 0; i -= 1) { result = (result << 1) ^ ((result >>> 7) * 0x11d); result ^= ((y >>> i) & 1) * x; }
        return result;
    };
    const divisor = () => {
        const result = Array(18).fill(0); result[17] = 1;
        let root = 1;
        for (let i = 0; i < 18; i += 1) {
            for (let j = 0; j < 18; j += 1) { result[j] = multiply(result[j], root); if (j + 1 < 18) result[j] ^= result[j + 1]; }
            root = multiply(root, 2);
        }
        return result;
    };
    const remainder = (data) => {
        const poly = divisor(), result = Array(18).fill(0);
        for (const value of data) { const factor = value ^ result.shift(); result.push(0); for (let i = 0; i < 18; i += 1) result[i] ^= multiply(poly[i], factor); }
        return result;
    };
    const encode = text => {
        const bytes = Array.from(new TextEncoder().encode(text));
        if (bytes.length > 134) throw new Error('反馈入口URL超过二维码134字节容量，请使用较短的入口键或域名');
        const bits = [], push = (value, count) => { for (let i = count - 1; i >= 0; i -= 1) bits.push((value >>> i) & 1); };
        push(4, 4); push(bytes.length, 8); bytes.forEach(value => push(value, 8)); push(0, Math.min(4, 1088 - bits.length));
        while (bits.length % 8) bits.push(0);
        const data = []; for (let i = 0; i < bits.length; i += 8) data.push(bits.slice(i, i + 8).reduce((value, bit) => (value << 1) | bit, 0));
        for (let pad = 0; data.length < 136; pad += 1) data.push(pad % 2 ? 0x11 : 0xec);
        const blocks = [data.slice(0, 68), data.slice(68)], ecc = blocks.map(remainder), codewords = [];
        for (let i = 0; i < 68; i += 1) codewords.push(blocks[0][i], blocks[1][i]);
        for (let i = 0; i < 18; i += 1) codewords.push(ecc[0][i], ecc[1][i]);
        const modules = Array.from({ length: SIZE }, () => Array(SIZE).fill(false));
        const reserved = Array.from({ length: SIZE }, () => Array(SIZE).fill(false));
        const mark = (x, y, dark) => { if (x >= 0 && y >= 0 && x < SIZE && y < SIZE) { modules[y][x] = !!dark; reserved[y][x] = true; } };
        for (let i = 0; i < SIZE; i += 1) { mark(6, i, i % 2 === 0); mark(i, 6, i % 2 === 0); }
        const finder = (cx, cy) => { for (let dy = -4; dy <= 4; dy += 1) for (let dx = -4; dx <= 4; dx += 1) { const distance = Math.max(Math.abs(dx), Math.abs(dy)); mark(cx + dx, cy + dy, distance !== 2 && distance !== 4); } };
        finder(3, 3); finder(SIZE - 4, 3); finder(3, SIZE - 4);
        for (let dy = -2; dy <= 2; dy += 1) for (let dx = -2; dx <= 2; dx += 1) mark(34 + dx, 34 + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
        const format = 0x77c4; // BCH format for L / mask 0, including the standard XOR mask.
        const bit = i => ((format >>> i) & 1) !== 0;
        for (let i = 0; i <= 5; i += 1) mark(8, i, bit(i));
        mark(8, 7, bit(6)); mark(8, 8, bit(7)); mark(7, 8, bit(8));
        for (let i = 9; i < 15; i += 1) mark(14 - i, 8, bit(i));
        for (let i = 0; i < 8; i += 1) mark(SIZE - 1 - i, 8, bit(i));
        for (let i = 8; i < 15; i += 1) mark(8, SIZE - 15 + i, bit(i));
        mark(8, SIZE - 8, true);
        let index = 0;
        for (let right = SIZE - 1; right >= 1; right -= 2) {
            if (right === 6) right = 5;
            for (let vertical = 0; vertical < SIZE; vertical += 1) {
                const y = ((right + 1) & 2) === 0 ? SIZE - 1 - vertical : vertical;
                for (let offset = 0; offset < 2; offset += 1) {
                    const x = right - offset;
                    if (reserved[y][x]) continue;
                    const value = index < codewords.length * 8 ? ((codewords[index >>> 3] >>> (7 - (index & 7))) & 1) !== 0 : false;
                    modules[y][x] = value !== ((x + y) % 2 === 0); index += 1;
                }
            }
        }
        return modules;
    };
    const svg = (url) => {
        const matrix = encode(url), path = [];
        matrix.forEach((row, y) => row.forEach((dark, x) => { if (dark) path.push(`M${x + 4},${y + 4}h1v1h-1z`); }));
        return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 49 49" width="245" height="245" role="img" aria-label="已鉴权员工反馈入口二维码"><rect width="49" height="49" fill="white"/><path d="${path.join('')}" fill="black"/></svg>`;
    };
    window.SUXI_GUEST_FEEDBACK_QR = Object.freeze({ encode, svg, version: 6, errorCorrection: 'L', capacityBytes: 134 });
})();
