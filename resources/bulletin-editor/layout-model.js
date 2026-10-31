/* Editable page furniture and result organization. Prototype only. */
(function(root){
  'use strict';
  const M=typeof module!=='undefined'&&module.exports?require('./proposal-model.js'):root.ReportPrototypeModel;
  if(!M.layoutReady){
    const number=(v,f,min,max)=>Number.isFinite(Number(v))?M.clamp(Number(v),min,max):f;
    const pick=(v,list,f)=>list.includes(v)?v:f;
    const color=(v,f)=>M.color(v)?v:f;
    const string=(v,f='',limit=2500)=>typeof v==='string'?v.slice(0,limit):f;
    M.paperFormats={carta:{name:'Carta',w:816,h:1056,print:'letter'},a4:{name:'A4',w:794,h:1123,print:'A4'},oficio:{name:'Legal',w:816,h:1344,print:'legal'},a5:{name:'A5',w:559,h:794,print:'A5'}};
    M.pageSize=doc=>{const p=M.paperFormats[doc.format]||M.paperFormats.carta;return doc.orientation==='landscape'?{w:p.h,h:p.w}:{w:p.w,h:p.h};};
    M.pageView=(doc,page={})=>({...doc,format:page.format||doc.format,orientation:page.orientation||doc.orientation});
    M.resizePage=(doc,index,format,orientation)=>{const page=doc.pages[index];if(!page||page.locked)return doc;const view=M.pageView(doc,page),copy={...view,pages:[page]};M.resizePaper(copy,format,orientation);page.format=copy.format;page.orientation=copy.orientation;return doc;};
    M.resizePaper=(doc,format=doc.format,orientation=doc.orientation)=>{const before=M.pageSize(doc);if(M.paperFormats[format])doc.format=format;if(['portrait','landscape'].includes(orientation))doc.orientation=orientation;const after=M.pageSize(doc),margin=doc.pagination?.margin||44,flow=!!doc.pagination?.enabled,ratio=flow?(after.w-2*margin)/(before.w-2*margin):after.w/before.w;for(const p of doc.pages)for(const n of p.blocks){n.x=flow?margin+(n.x-margin)*ratio:n.x*ratio;n.w*=ratio;if(!flow){n.y*=after.h/before.h;n.h*=after.h/before.h;}else if(['image','circle','rectangle','line'].includes(n.type))n.h*=ratio;M.constrain(n,after);}return doc;};
    M.normalizeRule=(r={},fallback='#6A23FF')=>{r=r&&typeof r==='object'?r:{};return ({enabled:r.enabled!==false,color:color(r.color,fallback),width:number(r.width,1,0,12),style:pick(r.style,['solid','dashed','dotted','double'],'solid')});};
    const typography=(r={},f={})=>{r=r&&typeof r==='object'?r:{};return ({font:pick(r.font,M.fontFamilies,f.font||'Inter'),fontSize:number(r.fontSize,f.fontSize||10,8,36),color:color(r.color,f.color||'#644690'),bold:!!r.bold,italic:!!r.italic,underline:!!r.underline,align:pick(r.align,['left','center','right'],f.align||'left')});};
    M.normalizeSheet=(raw={},flow=true)=>{raw=raw&&typeof raw==='object'?raw:{};return ({
      background:color(raw.background,'#FFFFFF'),
      frame:{...M.normalizeRule(raw.frame,'#D8C8ED'),enabled:raw.frame?.enabled??flow,inset:number(raw.frame?.inset,20,0,90),radius:number(raw.frame?.radius,9,0,70)},
      footer:{...typography(raw.footer),enabled:raw.footer?.enabled!==false,text:string(raw.footer?.text,'Datos de muestra · [Nombre del colegio]'),locked:!!raw.footer?.locked,free:!!raw.footer?.free,left:number(raw.footer?.left,44,0,2000),bottom:number(raw.footer?.bottom,20,0,2000),width:number(raw.footer?.width,480,50,2000),height:raw.footer?.height==null?null:number(raw.footer.height,22,22,2000),rule:M.normalizeRule(raw.footer?.rule,'#D8C8ED')},
      numbering:{...typography(raw.numbering,{align:'right'}),enabled:raw.numbering?.enabled!==false,text:string(raw.numbering?.text,'Página [Página actual] de [Total de páginas]',300),locked:!!raw.numbering?.locked,free:!!raw.numbering?.free,right:number(raw.numbering?.right,44,0,2000),bottom:number(raw.numbering?.bottom,20,0,2000),width:number(raw.numbering?.width,180,50,2000),height:raw.numbering?.height==null?null:number(raw.numbering.height,22,22,2000),start:Math.round(number(raw.numbering?.start,1,1,9999)),skipFirst:!!raw.numbering?.skipFirst}
    });};
    Object.assign(M.fields,{page:'Página actual',pages:'Total de páginas',scaleLegend:'Escala de valoración del SIEE'});
    const normalizeTable=M.normalizeTable;
    M.normalizeTable=(raw={})=>{raw=raw&&typeof raw==='object'?raw:{};const t=normalizeTable(raw);t.rowMode=pick(raw.rowMode,['subjects','areas','areas-subjects'],'subjects');t.areaMethod=pick(raw.areaMethod,['simple','hours','weighted'],'simple');t.areaWeights={};for(const [key,value] of Object.entries(raw.areaWeights||{}).slice(0,60))if(/^[a-z0-9-]{1,40}$/.test(key))t.areaWeights[key]=number(value,0,0,100);t.borderStyle=pick(raw.borderStyle,['solid','dashed','dotted','double'],'solid');t.borderWidth=number(raw.borderWidth,1,0,5);t.borderColor=color(raw.borderColor,'#183C67');t.headerColor=color(raw.headerColor,'#FFFFFF');t.headerFill=color(raw.headerFill,'#183C67');t.headerBold=raw.headerBold!==false;t.headerFont=pick(raw.headerFont,M.fontFamilies,'Inter');t.areaColor=color(raw.areaColor,'#13264D');t.areaFill=color(raw.areaFill,'#EEE7FF');t.areaBold=raw.areaBold!==false;t.valueBold=raw.valueBold!==false;t.subjectBold=raw.subjectBold!==false;t.showHead=raw.showHead!==false;t.binding='student-academic-results';return t;};
    M.defaultContent=(n,doc)=>({
      headerTitle:'[Nombre del colegio]',headerBody:'[Resolución del colegio]\nCódigo DANE: [Código DANE]\nNIT: [NIT del colegio]\n[Dirección del colegio]\n[Teléfono del colegio] · [Correo del colegio]\nINFORME ACADÉMICO · [Año lectivo]',
      student:'NOMBRE: [Nombre del estudiante]\n[Grado y grupo] · [Período] · [Año lectivo]',scaleTitle:'Escala valorativa',scaleBody:'{{scaleLegend}}',scaleNote:'I.H.: intensidad horaria semanal',
      observationTitle:doc.scope==='final'?'Observación final · Opcional':'Observaciones',attendance:'Inasistencias: [Inasistencias]',promotion:'[Resultado de promoción]',signatureTeacher:'[Nombre del docente]\nDocente',signaturePrincipal:'[Nombre del rector o rectora]\nRectoría',indicatorsTitle:'Indicadores de aprendizaje'
    });
    const validate=M.validate;
    M.validate=input=>{
      if(!input||!M.paperFormats[input.format])throw new Error('Revisa el tamaño de papel.');
      const doc=validate({...input,format:['carta','a4'].includes(input.format)?input.format:'carta'});doc.format=input.format;if(input.layout==='canvas'){doc.layout='canvas';doc.designer={rows:input.designer?.rows===1?1:2};}
      doc.pages.forEach((p,i)=>{const source=input.pages[i];p.title=string(source.title,'',100);p.hidden=!!source.hidden;p.locked=!!source.locked;if(M.paperFormats[source.format])p.format=source.format;if(['portrait','landscape'].includes(source.orientation))p.orientation=source.orientation;const size=M.pageSize(M.pageView(doc,p));p.blocks.forEach((n,j)=>{const raw=source.blocks[j];if(raw.appearance&&typeof raw.appearance==='object')n.appearance=M.normalizeDesign(raw.appearance);n.headerPart=raw.headerPart===true;n.source=raw.source==='school-logo'?'school-logo':'asset';if(typeof raw.groupKey==='string'&&/^[A-Za-z0-9_-]{1,80}$/.test(raw.groupKey))n.groupKey=raw.groupKey;n.captionBold=raw.captionBold!==false;n.underline=!!raw.underline;n.strike=!!raw.strike;n.lineHeight=number(raw.lineHeight,1.5,1,3);n.letterSpacing=number(raw.letterSpacing,0,-1,8);n.rule=M.normalizeRule(raw.rule,n.accent);n.content={};for(const key of Object.keys(M.defaultContent(n,doc)))if(typeof raw.content?.[key]==='string')n.content[key]=raw.content[key].slice(0,2500);if(raw.type==='table'||raw.type==='grades'&&raw.table)n.table=M.normalizeTable(raw.table);M.constrain(n,size);n.w=M.clamp(Number(raw.w)||n.w,24,size.w-16);n.h=M.clamp(Number(raw.h)||n.h,3,size.h-16);n.x=M.clamp(Number(raw.x)||0,0,size.w-n.w);n.y=M.clamp(Number(raw.y)||0,0,size.h-n.h);});});
      doc.sheet=M.normalizeSheet(input.sheet,!!doc.pagination?.enabled);return doc;
    };
    M.layoutReady=true;
  }
  if(typeof module!=='undefined'&&module.exports)module.exports=M;
})(typeof window==='undefined'?globalThis:window);
