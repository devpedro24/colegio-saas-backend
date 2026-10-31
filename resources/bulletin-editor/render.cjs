'use strict';
const fs=require('node:fs');
const M=require('./layout-model.js'),R=require('./report-engine.js'),C=require('./canvas-engine.js'),D=require('./page-layout.js');
const input=JSON.parse(fs.readFileSync(0,'utf8'));
input.doc.id=input.doc.key;for(const page of input.doc.pages){page.id=page.key;for(const node of page.blocks)node.id=node.key;}
const doc=M.validate(input.doc);doc.layout='canvas';doc.name=input.doc.name;
doc.runtime=input.runtime;doc.institution=input.institution;doc.school=input.institution.name;
doc.assets=input.assets||{};
for(const row of [...doc.runtime.rows,...doc.runtime.areaRows]){row.icon=doc.runtime.icons?.[row.iconKey]||null;for(const child of row.children||[])child.icon=doc.runtime.icons?.[child.iconKey]||null;}
const escape=R.escape;
const unescape=value=>value.replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&quot;/g,'"').replace(/&#39;/g,"'").replace(/&nbsp;/g,' ');
function lineCount(html,width,fontSize){
  const text=unescape(html.replace(/<br\s*\/?\s*>|<\/p>|<\/li>|<\/div>/gi,'\n').replace(/<[^>]*>/g,''));
  const chars=Math.max(4,Math.floor(width/(fontSize*.57)));
  return text.split('\n').reduce((n,line)=>n+Math.max(1,Math.ceil(line.length/chars)),0);
}
function measure(html,node){
  const fontSize=node.fontSize||12,lineHeight=node.lineHeight||1.5;
  if(html.includes('<table')){
    const cols=[...html.matchAll(/<col style="width:([\d.]+)%/g)].map(match=>Number(match[1])/100);
    let height=0;
    for(const row of html.matchAll(/<tr\b[^>]*>([\s\S]*?)<\/tr>/g)){
      const cells=[...row[1].matchAll(/<(?:td|th)\b[^>]*>([\s\S]*?)<\/(?:td|th)>/g)];
      const cellHeights=cells.map((cell,index)=>{
        const rich=cell[1].includes('indicator-list');
        // PDF lists include indentation, block breaks and per-item margins.
        // Counting only stripped text let the last indicator run past its box.
        const content=rich?cell[1].replace(/<(?:ul|ol)\b[^>]*>/gi,'\n'):cell[1];
        const width=Math.max(25,node.w*(cols[index]||1/cells.length)-24-(rich?22:0));
        const spacing=rich?(cell[1].match(/<li\b/gi)||[]).length*6+(cell[1].match(/<(?:ul|ol|p)\b/gi)||[]).length*8:0;
        return lineCount(content,width,fontSize)*fontSize*lineHeight+spacing;
      });
      height+=Math.max(row[1].includes('valuation-image')||row[1].includes('grade-face')?84:0,Math.max(1,...cellHeights)+28);
    }
    return Math.ceil(height+2);
  }
  if(node.type==='header')return Math.max(node.h||0,118,lineCount(html,Math.max(100,node.w-110),fontSize)*fontSize*1.5+20);
  if(node.type==='signature')return Math.max(node.h||0,65);
  return Math.max(22,lineCount(html,Math.max(40,node.w-2*(node.padding||0)),fontSize)*fontSize*lineHeight+(node.type==='text'?2*(node.padding||0):12));
}
const layout=C.emit(doc,null,'values',measure),size=M.pageSize(doc);
if(!layout.pages.length)throw new Error('El boletín no contiene páginas visibles.');
const css=fs.readFileSync(__dirname+'/print.css','utf8');
let rules='',body='';const pageSizes=[];
function styleFor(n,index){
  const t=n.table||{},key='b'+index+'-'+String(n.id).replace(/[^a-zA-Z0-9_-]/g,''),appearance={...doc.design,...n.appearance};
  rules+=`#${key} .flow-table th{background:${t.headerFill||n.accent};color:${t.headerColor||'#ffffff'};font-family:'${t.headerFont||n.font}';font-weight:${t.headerBold===false?400:700}}`;
  rules+=`#${key} .flow-table.with-borders th,#${key} .flow-table.with-borders td{border:${t.borderWidth??1}px ${t.borderStyle||'solid'} ${t.borderColor||n.accent}}`;
  rules+=`#${key} .flow-table.without-borders th,#${key} .flow-table.without-borders td{border:0}#${key} .area-result td{background:${t.areaFill||'#eee7ff'};color:${t.areaColor||'#13264d'};font-weight:${t.areaBold===false?400:700}}`;
  rules+=`#${key} .flow-masthead{border-color:${n.rule?.color||n.accent};border-style:${n.rule?.style||'solid'};border-width:0 0 ${n.rule?.enabled===false?0:n.rule?.width??1}px}#${key} .signature-line{border-color:${n.rule?.color||n.accent}}`;
  rules+=`#${key} .dimension-row th{background:${t.areaFill||'#eee7ff'};color:${t.areaColor||'#13264d'};text-align:left}#${key} .subject-name{font-weight:${t.subjectBold?700:400}}#${key} .column-grade strong{font-weight:${t.valueBold===false?400:700}}`;
  const head=appearance.header;
  if(head==='band')rules+=`#${key} .flow-masthead{background:${n.accent};color:${appearance.headerInk||'#ffffff'};padding:15px;border:0;border-radius:10px;text-align:left}#${key} .flow-masthead img{background:#fff}`;
  if(head==='side')rules+=`#${key} .flow-masthead{border-left:7px solid ${n.accent};border-bottom:0;padding:10px 18px;text-align:left}`;
  if(head==='editorial')rules+=`#${key} .flow-masthead{border-top:3px solid ${n.accent};text-align:center}#${key} .flow-masthead img{float:none;display:block;margin:0 auto 7px;width:48px;height:48px}#${key} .flow-masthead>div{margin-left:0}`;
  if(head==='compact')rules+=`#${key} .flow-masthead{text-align:left;min-height:60px}#${key} .flow-masthead img{width:48px;height:48px}#${key} .flow-masthead>div{margin-left:65px}`;
  if(head==='playful')rules+=`#${key} .flow-masthead{background:#f4efff;border-radius:24px;padding:17px;text-align:left}#${key} .flow-masthead img{float:right;margin-left:15px}#${key} .flow-masthead>div{margin-left:0;margin-right:90px}`;
  if(appearance.table==='striped')rules+=`#${key} tbody tr:nth-child(even) td{background:#f5f1fb}`;
  if(appearance.table==='soft')rules+=`#${key} .flow-table{border-collapse:separate;border-spacing:0 4px}#${key} .flow-table td{background:#f5f1fb;border:0}`;
  return key;
}
for(const [index,page] of layout.pages.entries()){
  const view=M.pageView(doc,page),pageSize=page.size||M.pageSize(view),name='bulletinPage'+index;
  pageSizes.push({width:pageSize.w,height:pageSize.h});
  rules+=`@page ${name}{size:${pageSize.w}px ${pageSize.h}px;margin:0}`;
  body+=`<div class="print-page" style="page:${name};width:${pageSize.w}px;height:${pageSize.h}px">`;
  for(const n of page.blocks){
    const key=styleFor(n,index);
    body+=`<div id="${key}" class="canvas-block" style="left:${n.x}px;top:${n.y}px;width:${n.w}px;height:${n.h}px;color:${n.color};font-family:'${n.font}';font-size:${n.fontSize}px;font-weight:${n.bold?700:400};font-style:${n.italic?'italic':'normal'};line-height:${n.lineHeight||1.5};letter-spacing:${n.letterSpacing||0}px;text-decoration:${n.underline?'underline':n.strike?'line-through':'none'};text-align:${n.align};background:${['line','rectangle','circle'].includes(n.type)?'transparent':n.fill};border-radius:${n.radius}px">${n._html}</div>`;
  }
  body+=D.render(view,layout.ctx,index,layout.pages.length).replace(/inset:([\d.]+)px/g,'top:$1px;right:$1px;bottom:$1px;left:$1px')+'</div>';
}
process.stdout.write(JSON.stringify({width:size.w,height:size.h,pages:layout.pages.length,pageSizes,
  html:'<!doctype html><html><head><meta charset="utf-8"><title>'+escape(doc.name)+'</title><style>'+css+rules+'</style></head><body>'+body+'</body></html>'}));
