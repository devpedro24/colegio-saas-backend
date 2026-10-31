/* Independent prototype model. No academic API or real student records. */
(function (root) {
  'use strict';
  const fontFamilies=(typeof module!=='undefined'&&module.exports?require('./font-catalog.js'):root.ReportFontCatalog).families;
  const uid = () => typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : 'demo-' + Date.now().toString(36) + Math.random().toString(36).slice(2);
  const clone = value => JSON.parse(JSON.stringify(value));
  const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
  const titles = {header:'Encabezado del colegio',title:'Título del boletín',student:'Datos del estudiante',grades:'Calificaciones',table:'Tabla configurable',scale:'Escala valorativa',pagebreak:'Salto de página',indicators:'Indicadores',observation:'Observaciones',attendance:'Asistencia',signature:'Firmas',promotion:'Promoción',text:'Texto libre',field:'Dato del estudiante',rectangle:'Rectángulo',circle:'Círculo',line:'Línea',image:'Imagen'};
  const fields = {student:'Nombre del estudiante',grade:'Grado',group:'Grado y grupo',level:'Nivel educativo',year:'Año lectivo',period:'Período',teacher:'Docente',school:'Nombre del colegio',nextGrade:'Grado siguiente',promotionVerb:'Cursó / cursó y aprobó',promotion:'Resultado de promoción',absence:'Inasistencias',average:'Promedio',observation:'Observación del estudiante'};
  const columnTypes = {subject:'Asignatura',area:'Área / dimensión',indicators:'Indicadores',hours:'I.H.',grade:'Valoración',performance:'Desempeño',periods:'Períodos'};
  const tableDefaults = () => ({columns:[{key:'subject',label:'Asignatura',width:48},{key:'grade',label:'Valoración',width:22},{key:'performance',label:'Desempeño',width:30}],groupBy:'none',indicatorsInSubject:false,repeatHead:true,borders:true,density:'normal',subjectKeys:[],filterSubjects:false,groupLabel:'Dimensión'});
  const sizes = {header:[708,105],title:[708,68],student:[708,90],grades:[708,220],indicators:[708,119],observation:[708,100],attendance:[708,52],signature:[708,60],promotion:[708,62],text:[390,75],field:[350,55],rectangle:[260,95],circle:[120,120],line:[400,4],image:[155,155]};
  function pageSize(doc) {let [w,h] = doc.format === 'a4' ? [794,1123] : [816,1056]; return doc.orientation === 'landscape' ? {w:h,h:w} : {w,h};}
  function block(type, x = 54, y = 100, options = {}) {
    const [w,h] = sizes[type] || sizes.text;
    return {id:uid(),type,x,y,w,h,hidden:false,locked:false,name:titles[type] || 'Elemento',text:type === 'title' ? titles.title : type === 'text' ? 'Escribe aquí tu texto' : type === 'observation' ? 'Demuestra compromiso y participación constante en las actividades del período.' : '',color:'#13264D',fill:['rectangle','circle'].includes(type)?'#eee6ff':type==='line'?'#6a23ff':'transparent',font:'Inter',fontSize:17,bold:type==='title',italic:false,align:type==='title'?'center':'left',accent:'#6A23FF',radius:8,padding:['text','title'].includes(type)?0:12,lineHeight:['text','title'].includes(type)?1.2:1.5,showGrade:true,showPerformance:true,showPeriods:true,showPromotion:true,field:'student',repeat:type==='header',keepWithNext:false,...(type==='table'?{table:tableDefaults()}:{}),...options};
  }
  function documentDesign(kind = 'institutional', scope = 'periodo') {
    const final = scope === 'final';
    const page = {id:uid(),blocks:[]};
    const doc = {schema:1,id:uid(),name:kind==='blank'?'Mi nueva plantilla':final?'Boletín final · Primaria':kind==='visual'?'Valoración con caritas':'Boletín de período · Primaria',scope,format:'carta',orientation:'portrait',school:'Colegio Inmaculada',sampleScale:kind==='visual'?'visual':'numeric',accent:kind==='minimal'?'#183c67':'#6a23ff',pages:[page],assets:{}};
    if (kind === 'blank') return doc;
    doc.schema=2;doc.layout='flow';doc.pagination={enabled:true,margin:44,gap:14,repeatHeader:true};
    page.blocks = [block('header',54,35),block('title',54,154,{text:final?'INFORME ACADÉMICO FINAL · {{year}}':'INFORME ACADÉMICO · {{period}} · {{year}}'}),block('student',54,236),block('grades',54,350,{h:final?242:220}),...(!final?[block('indicators',54,599),block('observation',54,748)]:[block('promotion',54,641),block('attendance',54,730)]),block('signature',54,918)];
    if (kind==='visual') page.blocks.find(n=>n.type==='grades').h=220;
    for(const item of page.blocks) {item.accent=doc.accent;item.padding=0;item.fontSize=item.type==='header'?16:item.type==='title'?15:12;}
    return doc;
  }
  function constrain(node, size) {
    node.w=clamp(Number(node.w)||80,24,size.w-16); node.h=clamp(Number(node.h)||24,3,size.h-16);
    node.x=clamp(Number(node.x)||0,0,size.w-node.w);node.y=clamp(Number(node.y)||0,0,size.h-node.h);
    return node;
  }
  const color = value => typeof value==='string' && /^#[a-f\d]{6}$/i.test(value);
  function validate(input) {
    if(!input || ![1,2].includes(input.schema) || !Array.isArray(input.pages) || input.pages.length<1 || input.pages.length>12) throw new Error('El archivo no es un diseño compatible.');
    if(!['periodo','final'].includes(input.scope) || !['carta','a4'].includes(input.format) || !['portrait','landscape'].includes(input.orientation)) throw new Error('Revisa el tipo y el formato del diseño.');
    const doc=documentDesign('blank',input.scope), size=pageSize(input);
    doc.schema=input.schema;if(input.layout==='flow'||input.schema===2&&input.pagination?.enabled&&input.pages.every(p=>p.blocks?.every(n=>n.y===0)))doc.layout='flow';
    if(input.pagination)doc.pagination={enabled:!!input.pagination.enabled,margin:clamp(Number(input.pagination.margin)||44,24,90),gap:clamp(Number(input.pagination.gap)||14,4,40),repeatHeader:input.pagination.repeatHeader!==false};
    if(input.sample)doc.sample={profile:input.sample.profile==='preschool'?'preschool':'primary',student:['one','two','pending'].includes(input.sample.student)?input.sample.student:'one',year:/^\d{4}$/.test(String(input.sample.year))?String(input.sample.year):'2026',periods:clamp(Number(input.sample.periods)||4,1,6),count:clamp(Number(input.sample.count)||14,1,60),length:['short','normal','long'].includes(input.sample.length)?input.sample.length:'normal',period:clamp(Number(input.sample.period)||3,1,6)};
    doc.name=String(input.name||'Plantilla importada').slice(0,100);doc.school=String(input.school||'Colegio de muestra').slice(0,100);doc.format=input.format;doc.orientation=input.orientation;doc.accent=color(input.accent)?input.accent:doc.accent;doc.sampleScale=input.sampleScale==='visual'?'visual':'numeric';
    doc.assets={};for(const [key,value] of Object.entries(input.assets||{})) {if(Object.keys(doc.assets).length>=12) throw new Error('El diseño tiene demasiadas imágenes.');if(typeof value!=='string'||!/^data:image\/(png|jpeg|webp);base64,[a-z\d+/=\s]+$/i.test(value)||value.length>4000000) throw new Error('Una imagen del diseño no es válida.');doc.assets[key]=value;}
    let count=0;doc.pages=input.pages.map(p=>({id:uid(),blocks:(Array.isArray(p.blocks)?p.blocks:[]).map(raw=>{if(++count>160 || !Object.hasOwn(titles,raw.type)) throw new Error('El diseño contiene elementos no compatibles.');const n=block(raw.type);for(const key of ['x','y','w','h','fontSize','radius','padding']) if(Number.isFinite(Number(raw[key]))) n[key]=Number(raw[key]);for(const key of ['bold','italic','hidden','locked','showGrade','showPerformance','showPeriods','showPromotion','repeat','keepWithNext']) if(typeof raw[key]==='boolean') n[key]=raw[key];for(const key of ['color','accent']) if(color(raw[key])) n[key]=raw[key];if(color(raw.fill)||raw.fill==='transparent') n.fill=raw.fill;if(fontFamilies.includes(raw.font))n.font=raw.font;if(['left','center','right','justify'].includes(raw.align))n.align=raw.align;n.name=String(raw.name||n.name).slice(0,100);n.text=String(raw.text||'').slice(0,10000);n.field=Object.hasOwn(fields,raw.field)?raw.field:'student';if(raw.type==='table')n.table=normalizeTable(raw.table);if(typeof raw.asset==='string' && doc.assets[raw.asset])n.asset=raw.asset;n.fontSize=clamp(n.fontSize,8,90);n.radius=clamp(n.radius,0,100);n.padding=clamp(n.padding,0,60);return constrain(n,size);})}));
    return doc;
  }
  function normalizeTable(raw={}){const t=tableDefaults();if(!raw||typeof raw!=='object')return t;const seen=new Set();if(Array.isArray(raw.columns)){const columns=raw.columns.slice(0,7).filter(c=>c&&Object.hasOwn(columnTypes,c.key)&&!seen.has(c.key)&&seen.add(c.key)).map(c=>({key:c.key,label:String(c.label||columnTypes[c.key]).slice(0,60),width:clamp(Number(c.width)||20,5,90)}));if(columns.length)t.columns=columns;}t.filterSubjects=!!raw.filterSubjects;t.groupBy=raw.groupBy==='area'?'area':'none';t.indicatorsInSubject=!!raw.indicatorsInSubject;t.repeatHead=raw.repeatHead!==false;t.borders=raw.borders!==false;t.density=raw.density==='compact'?'compact':'normal';t.groupLabel=String(raw.groupLabel||'Dimensión').slice(0,60);t.subjectKeys=Array.isArray(raw.subjectKeys)?[...new Set(raw.subjectKeys.filter(k=>typeof k==='string'&&/^[a-z0-9-]{1,40}$/.test(k)))].slice(0,60):[];return t;}
  const api={fontFamilies,uid,clone,clamp,titles,fields,sizes,block,documentDesign,pageSize,constrain,validate,color,columnTypes,tableDefaults,normalizeTable};
  root.ReportPrototypeModel=api;
  if(typeof module!=='undefined'&&module.exports) module.exports=api;
})(typeof window==='undefined'?globalThis:window);
