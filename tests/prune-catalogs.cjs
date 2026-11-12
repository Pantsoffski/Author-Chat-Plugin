// Keep existing translations of active singular strings; preserve translator credits.
const fs = require('node:fs');
const sources = ['acppauthorchat-author-chat.php','acppauthorchat-options.php','acppauthorchat-process.php','acppauthorchat-schema.php','acppauthorchat_chat.js'].map(f=>fs.readFileSync(f,'utf8')).join('\n');
const used = new Set([...sources.matchAll(/\b(?:__|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*('(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*")/g)].map(m=>m[1][0]==='"'?JSON.parse(m[1]):m[1].slice(1,-1).replace(/\\'/g,"'").replace(/\\\\/g,'\\')));
function field(block, key) {
    const match = block.match(new RegExp('^'+key+' (".*")((?:\\n".*")*)','m'));
    return match ? [match[1],...match[2].split('\n').filter(Boolean)].map(x=>JSON.parse(x)).join('') : null;
}
for (const name of fs.readdirSync('lang').filter(x=>x.endsWith('.po'))) {
    const file='lang/'+name;
    const blocks=fs.readFileSync(file,'utf8').replace(/\r\n/g,'\n').trim().split(/\n\s*\n/);
    const entries=new Map(), kept=[];
    for (let block of blocks) {
        const id=field(block,'msgid');
        if (id===null || entries.has(id) || (id!=='' && !used.has(id))) continue;
        if (/msgid_plural|msgstr\[/.test(block)) throw Error('Plural catalog needs separate handling');
        block=block.replace(/Project-Id-Version: Author Chat [^\\]*\\n/,'Project-Id-Version: Author Chat 2.1.0\\n');
        entries.set(id,field(block,'msgstr')||''); kept.push(block);
    }
    fs.writeFileSync(file,kept.join('\n\n')+'\n');
    const pairs=[...entries].filter(([id, translation])=>id==='' || translation!=='').sort((a,b)=>Buffer.compare(Buffer.from(a[0]),Buffer.from(b[0])));
    const count=pairs.length, head=Buffer.alloc(28+16*count), data=[];
    [0x950412de,0,count,28,28+8*count,0,head.length].forEach((v,i)=>head.writeUInt32LE(v,i*4));
    let offset=head.length;
    for (let column=0;column<2;column++) pairs.forEach((pair,i)=>{
        const bytes=Buffer.from(pair[column]); const table=28+column*8*count+i*8;
        head.writeUInt32LE(bytes.length,table);head.writeUInt32LE(offset,table+4);
        data.push(bytes,Buffer.from([0]));offset+=bytes.length+1;
    });
    fs.writeFileSync(file.replace(/\.po$/,'.mo'),Buffer.concat([head,...data]));
    console.log(name+': retained '+count+' entries including metadata');
}
