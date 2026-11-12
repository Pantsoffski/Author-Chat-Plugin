// Original synthesized notification tone. GPL-2.0-or-later.
const fs = require('node:fs');
const path = require('node:path');
const rate = 22050, samples = 3308;
const wav = Buffer.alloc(44 + samples * 2);
wav.write('RIFF'); wav.writeUInt32LE(wav.length - 8, 4); wav.write('WAVEfmt ', 8);
wav.writeUInt32LE(16, 16); wav.writeUInt16LE(1, 20); wav.writeUInt16LE(1, 22);
wav.writeUInt32LE(rate, 24); wav.writeUInt32LE(rate * 2, 28);
wav.writeUInt16LE(2, 32); wav.writeUInt16LE(16, 34); wav.write('data', 36);
wav.writeUInt32LE(samples * 2, 40);
for (let i = 0; i < samples; i++) {
    wav.writeInt16LE(Math.round(5000 * Math.sin(2 * Math.PI * 660 * i / rate) * Math.sin(Math.PI * i / samples) ** 2), 44 + i * 2);
}
fs.writeFileSync(path.join(__dirname, '..', 'notifyauthorchat.wav'), wav);
