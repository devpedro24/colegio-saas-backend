/* Local document engine. All academic records below are fictitious. No SaaS API. */
(function(root){
  'use strict';
  const M=typeof module!=='undefined'&&module.exports?require('./layout-model.js'):root.ReportPrototypeModel;
  const D=typeof module!=='undefined'&&module.exports?require('./page-layout.js'):root.ReportPageLayout;
  const F=typeof module!=='undefined'&&module.exports?require('./field-guide.js'):root.ReportFieldGuide;
  const escape=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const numeric=v=>(Math.round((Number(v)+Number.EPSILON)*10)/10).toFixed(1);
  const performance=(v,ranges)=>Array.isArray(ranges)?ranges.find(r=>Number(numeric(v))>=Number(r.desde)&&Number(numeric(v))<=Number(r.hasta))?.nombre||'—':Number(numeric(v))>=4.6?'Superior':Number(numeric(v))>=4?'Alto':Number(numeric(v))>=3?'Básico':'Bajo';
  const faces=v=>Number(numeric(v))>=4.6?'😄':Number(numeric(v))>=4?'🙂':Number(numeric(v))>=3?'😐':'😟';
  const primary=[
    ['language','Lengua Castellana','Humanidades',7,'Comprende textos narrativos e identifica sus personajes y situaciones.','Expresa sus ideas mediante oraciones claras y escucha a sus compañeros.'],
    ['math','Pensamiento numérico · Matemáticas','Matemáticas',6,'Resuelve situaciones de suma y resta con material concreto.','Explica el procedimiento utilizado y comprueba sus respuestas.'],
    ['english','Lengua extranjera · Inglés','Humanidades',4,'Reconoce vocabulario de su entorno y responde a instrucciones sencillas.','Participa en canciones y juegos para comunicar ideas en inglés.'],
    ['science','Ciencias Naturales','Ciencias Naturales',3,'Describe características de los seres vivos y propone acciones de cuidado.','Observa su entorno, formula preguntas y registra sus hallazgos.'],
    ['social','Ciencias Sociales','Ciencias Sociales',3,'Reconoce los espacios de su comunidad y las normas de convivencia.','Relaciona acontecimientos cercanos con su historia familiar y escolar.'],
    ['sport','Educación Física','Educación Física',2,'Coordina movimientos y participa en juegos respetando sus reglas.','Practica hábitos de cuidado, hidratación y actividad física segura.'],
    ['emotion','Cátedra emocional y cívica','Convivencia',1,'Identifica sus emociones y propone formas respetuosas de expresarlas.','Participa en acuerdos que contribuyen al bienestar del grupo.'],
    ['art','Educación Artística','Educación Artística',1,'Explora colores, texturas y formas para comunicar sus ideas.','Comparte sus creaciones y aprecia las expresiones de sus compañeros.'],
    ['ethics','Ética y Valores','Convivencia',1,'Reconoce acciones de respeto y solidaridad en la vida cotidiana.','Asume responsabilidades y cuida los materiales de uso compartido.'],
    ['statistics','Pensamiento estadístico','Matemáticas',1,'Clasifica datos del entorno y los representa mediante dibujos y tablas.','Compara cantidades y explica la información que observa.'],
    ['geometry','Pensamiento geométrico','Matemáticas',1,'Reconoce figuras y describe posiciones y recorridos en el espacio.','Construye composiciones utilizando formas y patrones sencillos.'],
    ['technology','Tecnología e Informática','Tecnología',1,'Identifica herramientas tecnológicas y las usa de manera responsable.','Sigue instrucciones para desarrollar una actividad digital sencilla.'],
    ['reading','Plan lector y ortografía','Humanidades',2,'Lee textos breves y comunica lo que comprendió con sus propias palabras.','Revisa sus escritos y aplica las convenciones trabajadas en clase.'],
    ['peace','Cátedra de la Paz','Convivencia',1,'Escucha diferentes puntos de vista y busca soluciones dialogadas.','Reconoce sus derechos y sus responsabilidades dentro de la comunidad.']
  ];
  const preschool=[
    ['pre-math','Pre-Matemáticas','Dimensión Cognitiva',4,'Relaciona cantidades pequeñas con objetos de su entorno.','Reconoce formas, colores y posiciones mediante juegos y exploraciones.'],
    ['pre-science','Pre-Ciencias','Dimensión Cognitiva',3,'Reconoce características de animales, plantas y elementos del entorno.','Formula preguntas y expresa sus descubrimientos mediante dibujos y relatos.'],
    ['pre-language','Lenguaje','Dimensión Comunicativa',5,'Comprende relatos sencillos y participa en conversaciones respetando turnos.','Expresa sus necesidades, emociones e ideas mediante lenguaje verbal y corporal.'],
    ['pre-english','Pre-Inglés','Dimensión Comunicativa',2,'Reconoce palabras, saludos y canciones de uso cotidiano.','Participa en juegos de escucha e imitación con confianza.'],
    ['pre-body','Motricidad y expresión corporal','Dimensión Corporal',3,'Explora desplazamientos, posturas y movimientos de acuerdo con sus posibilidades.','Participa en juegos que fortalecen coordinación y equilibrio.'],
    ['pre-art','Expresión artística','Dimensión Estética',3,'Explora materiales y comunica sus vivencias mediante creaciones libres.','Disfruta la música, el dibujo y el movimiento como formas de expresión.'],
    ['pre-autonomy','Convivencia y autonomía','Dimensión Personal y Social',2,'Reconoce sus emociones y participa en acuerdos para compartir con otros.','Practica hábitos de cuidado personal y asume pequeñas responsabilidades.'],
    ['pre-writing','Pre-Escritura','Dimensión Comunicativa',2,'Realiza trazos libres e intencionados utilizando diferentes materiales.','Fortalece la coordinación de sus manos mediante juegos y actividades gráficas.']
  ];
  function context(doc,override){
    if(doc.runtime)return {...doc.runtime,fields:{page:'1',pages:'1',...doc.runtime.fields}};
    const s=doc.sample||{},profile=s.profile||(doc.sampleScale==='visual'?'preschool':'primary'),visual=(override||doc.sampleScale)==='visual';
    const student=s.student||'one',isPending=student==='pending',year=s.year||'2026',count=M.clamp(Number(s.count)||(profile==='preschool'?8:14),1,60),periodCount=M.clamp(Number(s.periods)||4,1,6);
    const base=profile==='preschool'?preschool:primary;
    const rows=Array.from({length:count},(_,i)=>{
      const a=base[i%base.length],extra=i>=base.length,key=extra?a[0]+'-'+(i+1):a[0];
      const periodValues=Array.from({length:periodCount},(_,p)=>Math.min(5,Math.max(1,(isPending?2.4:3.3)+((i*3+p*2+(student==='two'?4:0))%15)/10)));
      const grade=doc.scope==='final'?periodValues.reduce((a,b)=>a+b,0)/periodCount:periodValues[Math.min(periodCount,Number(s.period)||3)-1];
      const repeat=s.length==='long'?24:s.length==='short'?1:profile==='preschool'?4:2;
      const indicators=Array.from({length:repeat},(_,j)=>({key:key+'-i'+j,text:j<2?a[4+j]:[a[4],a[5],'Aplica lo aprendido en situaciones cercanas, explica sus avances y participa de manera activa en las experiencias propuestas.'][j%3]}));
      return {key,subject:a[1]+(extra?' · Proyecto '+(i+1):''),area:a[2],hours:a[3],grade,periodValues,indicators};
    });
    const gradeName=M.grades.includes(s.grade)?s.grade:profile==='preschool'?'Párvulos':'Primero',education=M.educationInfo(gradeName),level=education.level,institution=M.normalizeInstitution(doc.institution||{name:doc.school});
    const resultTable=doc.pages.filter(p=>!p.hidden).flatMap(p=>p.blocks).find(n=>n.table&&!n.hidden)?.table,t=resultTable?M.normalizeTable(resultTable):null,averageRows=t&&t.rowMode!=='subjects'?areaResults(rows.filter(r=>!t.filterSubjects||t.subjectKeys.includes(r.key)),t):rows;
    const fields={page:'1',pages:'3',student:student==='two'?'Tomás Rivera · Muestra':isPending?'Alex Moreno · Muestra':'Lucía Torres · Muestra',grade:gradeName,group:gradeName+' · '+education.group,level,year,period:'Período '+Math.min(periodCount,Number(s.period)||3),teacher:'Docente de muestra',school:institution.name,nit:institution.nit,dane:institution.dane,resolution:institution.resolution,address:institution.address,phone:institution.phone,email:institution.email,principal:institution.principal,nextGrade:isPending?'Pendiente':education.next,promotionVerb:isPending?'cursó':'cursó y aprobó',promotion:isPending?'Promoción pendiente de decisión':'Se promueve al grado '+education.next,absence:student==='two'?'3':'1',average:averageRows.length&&averageRows.every(r=>r.grade!==null)?numeric(averageRows.reduce((a,r)=>a+r.grade,0)/averageRows.length):'Pendiente',observation:student==='two'?'Avanza en la organización de su trabajo y participa con interés. Se recomienda continuar fortaleciendo sus hábitos de lectura.':'Participa con entusiasmo, comparte sus ideas y demuestra avances en las experiencias del período.'};
    fields.scaleLegend=institution.scaleLegend??'4.6 – 5.0: Desempeño Superior\n4.0 – 4.5: Desempeño Alto\n3.0 – 3.9: Desempeño Básico\n1.0 – 2.9: Desempeño Bajo';
    return {fields,rows,visual,profile,periodCount,year,isPending,performanceRanges:institution.performanceRanges};
  }
  function textLines(template,ctx,mode='values'){
    const lines=[''];
    for(const part of F.internalize(template).split(/(\{\{[a-zA-Z][a-zA-Z0-9_]*\}\})/g)){
      const match=part.match(/^\{\{([a-zA-Z][a-zA-Z0-9_]*)\}\}$/),key=match?.[1];
      if(key&&!Object.hasOwn(M.fields,key)){lines[lines.length-1]+='<span class="unknown-field">[Campo desconocido]</span>';continue;}
      const value=key?(mode==='fields'?'['+M.fields[key]+']':ctx.fields[key]):part;
      String(value??'').split(/\r?\n/).forEach((line,index)=>{if(index)lines.push('');lines[lines.length-1]+=key?`<span class="bound-value ${mode==='fields'?'field-chip':''}" data-bound-field="${key}">${escape(line)}</span>`:escape(line);});
    }
    return lines;
  }
  function text(template,ctx,mode='values'){return textLines(template,ctx,mode).join('<br>');}
  function tableConfig(n,doc){if(n.table)return M.normalizeTable(n.table);const t=M.tableDefaults();if(doc.scope==='final')t.columns=[{key:'subject',label:'Asignatura',width:40},{key:'periods',label:'P',width:35},{key:'grade',label:'Nota final',width:13},{key:'performance',label:'Desempeño',width:20}];t.columns=t.columns.filter(c=>!(c.key==='grade'&&n.showGrade===false)&&!(c.key==='performance'&&n.showPerformance===false)&&!(c.key==='periods'&&n.showPeriods===false));if(!t.columns.length)t.columns=[{key:'subject',label:'Asignatura',width:100}];return M.normalizeTable({...t,headerFill:n.accent,borderColor:n.accent});}
  function expandedColumns(table,ctx){return table.columns.flatMap(c=>c.key==='periods'?Array.from({length:ctx.periodCount},(_,i)=>({...c,key:'period-'+i,label:c.label+' '+(i+1),width:c.width/ctx.periodCount,period:i})):c);}
  function areaResults(rows,table){
    const groups=new Map();for(const row of rows){if(!groups.has(row.area))groups.set(row.area,[]);groups.get(row.area).push(row);}
    return [...groups.entries()].map(([name,children])=>{const weights=children.map(r=>table.areaMethod==='hours'?r.hours:table.areaMethod==='weighted'?table.areaWeights[r.key]||0:1),total=weights.reduce((a,b)=>a+b,0),valid=table.areaMethod!=='weighted'||Math.abs(total-100)<.01;
      const mean=values=>valid&&total?values.reduce((n,v,i)=>n+v*weights[i],0)/total:null;
      return {key:'area-'+children[0].key,subject:name,area:name,isArea:true,children,hours:children.reduce((n,r)=>n+r.hours,0),grade:mean(children.map(r=>r.grade)),periodValues:children[0].periodValues.map((_,p)=>mean(children.map(r=>r.periodValues[p]))),indicators:children.flatMap(r=>r.indicators.map(i=>({...i,text:r.subject+': '+i.text}))),weightValid:valid};
    });
  }
  function tableParts(n,doc,ctx){
    const table=tableConfig(n,doc),columns=expandedColumns(table,ctx),sum=columns.reduce((a,c)=>a+c.width,0);
    const colgroup='<colgroup>'+columns.map(c=>'<col style="width:'+c.width/sum*100+'%">').join('')+'</colgroup>';
    const head='<thead><tr>'+columns.map(c=>'<th style="width:'+c.width/sum*100+'%">'+escape(c.label)+'</th>').join('')+'</tr></thead>';
    let rows=ctx.rows.filter(r=>!table.filterSubjects||table.subjectKeys.includes(r.key));const units=[];
    if(table.rowMode!=='subjects'){
      for(const area of (ctx.actual?ctx.areaRows:areaResults(rows,table))){units.push({kind:'row',group:table.rowMode==='areas-subjects'?area.area:'',row:area,indicators:table.rowMode==='areas'?area.indicators:[],part:0});if(table.rowMode==='areas-subjects')for(const row of area.children)units.push({kind:'row',group:area.area,row:{...row,isChild:true},indicators:row.indicators,part:0});}
    }else{if(table.groupBy==='area')rows=[...rows].sort((a,b)=>a.area.localeCompare(b.area,'es'));let group='';for(const row of rows){if(table.groupBy==='area'&&group!==row.area){group=row.area;units.push({kind:'group',group,label:group});}units.push({kind:'row',group,row,indicators:row.indicators,part:0});}}
    return {table,columns,colgroup,head,units,style:{...doc.design,...n.appearance}};
  }
  function rowHTML(unit,parts,ctx){
    if(unit.kind==='group')return `<tr class="dimension-row" data-dimension="${escape(unit.group)}"><th colspan="${parts.columns.length}">${escape(unit.label||unit.group)}${unit.continuation?' · continuación':''}</th></tr>`;
    const row=unit.row,table=parts.table;
    const list='<ul class="indicator-list">'+unit.indicators.map(i=>`<li data-indicator-key="${escape(i.key)}">${ctx.actual&&i.html?i.html:escape(i.text)}</li>`).join('')+'</ul>';
    return `<tr class="${row.isArea?'area-result':row.isChild?'area-subject':''}" data-row-key="${escape(row.key)}" data-row-part="${unit.part||0}">${parts.columns.map(c=>{
      let value='';
      if(c.key==='subject')value=`<span class="subject-name">${escape(row.subject)}${unit.part?' · continuación':''}</span>`+(table.indicatorsInSubject?list:'');
      else if(c.key==='indicators')value=list;
      else if(c.key==='area')value=escape(row.area);
      else if(c.key==='hours')value=String(row.hours);
      else if(c.key==='performance')value=ctx.actual?escape(row.performance||'—'):row.grade===null?'Pendiente':escape(performance(row.grade,ctx.performanceRanges));
      else if(ctx.actual&&(c.key==='grade'||c.period!==undefined)){const display=c.period!==undefined?row.periodDisplay?.[c.period]:row.display;value=c.period!==undefined?escape(display??'—'):row.icon?'<img class="valuation-image" alt="'+escape(display??'')+'" src="'+escape(row.icon)+'"><small>'+escape(display??'')+'</small>':(row.emoji?'<span class="grade-face">'+escape(row.emoji)+'</span><small>'+escape(display??'')+'</small>':'<strong>'+escape(display??'—')+'</strong>');}
      else if(c.key==='grade'||c.period!==undefined){const valueNumber=c.period!==undefined?row.periodValues[c.period]:row.grade;value=valueNumber===null?'<span title="Configura los porcentajes del área al 100%">—</span>':ctx.visual?`<span class="grade-face" aria-label="${escape(performance(valueNumber))}">${faces(valueNumber)}</span><small>${escape(performance(valueNumber))}</small>`:`<strong>${numeric(valueNumber)}</strong>`;}
      return `<td class="column-${escape(c.key)}" style="width:${c.width/parts.columns.reduce((sum,col)=>sum+col.width,0)*100}%">${value}</td>`;
    }).join('')}</tr>`;
  }
  function tableHTML(parts,units,ctx,showHead=true){const t=parts.table;return '<table style="--table-ink:'+t.headerColor+';--table-fill:'+t.headerFill+';--table-head-font:&quot;'+t.headerFont+'&quot;;--table-head-weight:'+(t.headerBold?700:400)+';--area-ink:'+t.areaColor+';--area-fill:'+t.areaFill+';--area-weight:'+(t.areaBold?700:400)+';--subject-weight:'+(t.subjectBold?650:400)+';--value-weight:'+(t.valueBold?750:400)+';--cell-border:'+t.borderWidth+'px '+t.borderStyle+' '+t.borderColor+'" class="flow-table theme-'+escape(parts.style.table||'grid')+' '+(t.borders?'with-borders':'without-borders')+' '+(t.density==='compact'?'compact':'')+'">'+parts.colgroup+(showHead&&t.showHead?parts.head:'')+'<tbody>'+units.map(u=>rowHTML(u,parts,ctx)).join('')+'</tbody></table>';}
  function headerLines(n,doc,ctx){
    const school=M.normalizeInstitution(doc.institution||{name:doc.school}),c={...M.defaultContent(n,doc),...n.content};
    if(n.content&&Object.hasOwn(n.content,'headerBody'))return c.headerBody.split('\n').filter(line=>{const keys=[...F.internalize(line).matchAll(/\{\{([a-zA-Z][a-zA-Z0-9_]*)\}\}/g)].map(m=>m[1]);return !keys.length||keys.some(key=>ctx.fields[key]);});
    return [school.motto,school.resolution?'{{resolution}}':'',school.dane?'Código DANE: {{dane}}':'',school.nit?'NIT: {{nit}}':'',school.address?'{{address}}':'',[school.phone?'{{phone}}':'',school.email?'{{email}}':''].filter(Boolean).join(' · '),'INFORME ACADÉMICO · {{year}}'].filter(Boolean);
  }
  function repeatingHeader(doc,blocks=doc.pages.find(page=>page.blocks.some(n=>(n.type==='header'||n.headerPart)&&n.repeat&&!n.hidden))?.blocks||[]){
    const header=blocks.find(n=>n.type==='header'&&n.repeat&&!n.hidden);if(header)return header;
    const parts=blocks.filter(n=>n.headerPart&&n.repeat&&!n.hidden);if(!parts.length)return null;
    const x=Math.min(...parts.map(n=>n.x)),y=Math.min(...parts.map(n=>n.y)),w=Math.max(...parts.map(n=>n.x+n.w))-x,h=Math.max(...parts.map(n=>n.y+n.h))-y;
    return {...parts[0],id:'repeated-header-parts',type:'header',x,y,w,h,_repeatParts:parts};
  }
  function content(n,doc,ctx,mode='values'){
    if(n._repeatParts)return '<div style="position:relative;height:'+n.h+'px">'+n._repeatParts.map(p=>'<div style="position:absolute;left:'+(p.x-n.x)+'px;top:'+(p.y-n.y)+'px;width:'+p.w+'px;height:'+p.h+'px;font-family:&quot;'+escape(p.font)+'&quot;;font-size:'+p.fontSize+'px;font-weight:'+(p.bold?700:400)+';font-style:'+(p.italic?'italic':'normal')+';text-decoration:'+[p.underline?'underline':'',p.strike?'line-through':''].filter(Boolean).join(' ')+';color:'+p.color+';text-align:'+p.align+';line-height:'+p.lineHeight+';letter-spacing:'+p.letterSpacing+'px">'+content(p,doc,ctx,mode)+'</div>').join('')+'</div>';
    const c={...M.defaultContent(n,doc),...n.content},rule=M.normalizeRule(n.rule,n.accent),ruleStyle='--rule-color:'+rule.color+';--rule-width:'+(rule.enabled?rule.width:0)+'px;--rule-style:'+rule.style+';';
    if(n.type==='table'||n.type==='grades'){const parts=tableParts(n,doc,ctx);return tableHTML(parts,parts.units,ctx);}
    if(n.type==='scale')return '<div class="scale-legend"><strong data-edit-content="scaleTitle" style="font-weight:'+(n.captionBold===false?400:700)+'">'+text(c.scaleTitle,ctx,mode)+'</strong><p class="scale-ih" data-edit-content="scaleNote">'+text(c.scaleNote,ctx,mode)+'</p><div data-edit-content="scaleBody" style="display:block">'+textLines(c.scaleBody,ctx,mode).map((line,i)=>'<div class="scale-range">'+(ctx.visual&&!ctx.actual&&mode!=='fields'?'<span class="legend-face">'+faces([4.8,4.2,3.5,2][i%4])+'</span>':'')+'<span>'+line+'</span></div>').join('')+'</div></div>';
    if(n.type==='text')return '<div class="bound-text" data-edit-text style="padding:'+n.padding+'px">'+text(n.text,ctx,mode)+'</div>';
    if(n.type==='title')return '<div class="flow-title" data-edit-text>'+text(n.text,ctx,mode)+'</div>';
    if(n.type==='field')return '<div class="flow-field">'+text('{{'+n.field+'}}',ctx,mode)+'</div>';
    if(n.type==='observation')return '<div class="flow-observation"><strong data-edit-content="observationTitle" style="font-weight:'+(n.captionBold===false?400:700)+'">'+text(c.observationTitle,ctx,mode)+'</strong><div data-edit-text>'+text(n.text??'{{observation}}',ctx,mode)+'</div></div>';
    if(n.type==='header'){
      const school=M.normalizeInstitution(doc.institution||{name:doc.school}),design={...doc.design,...n.appearance},lines=headerLines(n,doc,ctx);
      return '<div class="flow-masthead rule-custom masthead-'+escape(design.header||'center')+'" style="color:'+escape(n.color)+';--header-ink:'+escape(design.headerInk||'#ffffff')+';'+ruleStyle+'"><img src="'+escape(school.logo||(ctx.actual?'':'assets/logo-muestra.svg'))+'" alt="Escudo del colegio"><div><strong data-edit-content="headerTitle" style="font-weight:'+(n.captionBold===false?400:700)+'">'+text(c.headerTitle,ctx,mode)+'</strong><div data-edit-content="headerBody">'+lines.map((line,i)=>'<div class="school-details-line" data-split-line="'+i+'">'+text(line,ctx,mode)+'</div>').join('')+'</div></div></div>';
    }
    if(n.type==='student')return '<div class="flow-student" data-edit-content="student">'+text(c.student,ctx,mode)+'</div>';
    if(n.type==='signature'){const signature=n.appearance?.signature||doc.design?.signature||'ambos',line='<span class="signature-line" style="border-top:'+(rule.enabled?rule.width:0)+'px '+rule.style+' '+rule.color+'"></span>';return '<div class="flow-signatures">'+(signature==='none'?'':signature==='docente'?'<div data-edit-content="signatureTeacher">'+line+text(c.signatureTeacher,ctx,mode)+'</div>':signature==='rector'?'<div data-edit-content="signaturePrincipal">'+line+text(c.signaturePrincipal,ctx,mode)+'</div>':'<div data-edit-content="signatureTeacher">'+line+text(c.signatureTeacher,ctx,mode)+'</div><div data-edit-content="signaturePrincipal">'+line+text(c.signaturePrincipal,ctx,mode)+'</div>')+'</div>';}
    if(n.type==='attendance')return '<div class="flow-attendance" data-edit-content="attendance">'+text(c.attendance,ctx,mode)+'</div>';
    if(n.type==='promotion')return '<div data-edit-content="promotion" class="flow-promotion '+(ctx.isPending?'pending':'')+'">'+text(c.promotion,ctx,mode)+'</div>';
    if(n.type==='indicators')return '<strong>'+text(c.indicatorsTitle,ctx,mode)+'</strong>'+ctx.rows.map(r=>'<div class="flow-observation"><strong>'+escape(r.subject)+'</strong><ul>'+r.indicators.map(i=>'<li>'+(ctx.actual&&i.html?i.html:escape(i.text))+'</li>').join('')+'</ul></div>').join('');
    if(n.type==='image')return '<img class="image-content" alt="Imagen del diseño" src="'+escape(n.source==='school-logo'?(doc.institution?.logo||(ctx.actual?'':'assets/logo-muestra.svg')):n.asset&&doc.assets[n.asset]?doc.assets[n.asset]:'assets/logo-muestra.svg')+'">';
    if(n.type==='line')return '<div class="shape-fill custom-line" style="'+ruleStyle+'height:0;border:0;border-top:'+(rule.enabled?rule.width:0)+'px '+rule.style+' '+rule.color+'"></div>';
    if(['rectangle','circle','line'].includes(n.type))return '<div class="shape-fill '+(n.type==='line'?'custom-line':'')+'" style="'+ruleStyle+'height:'+n.h+'px;background:'+escape(n.fill)+';border:'+(n.type==='line'?0:rule.enabled?rule.width:0)+'px '+rule.style+' '+rule.color+';border-radius:'+(n.type==='circle'?'50%':n.radius+'px')+'"></div>';
    return '';
  }
  let measureHost;
  function browserMeasure(html,n){
    if(!measureHost){measureHost=document.createElement('div');measureHost.className='pagination-measure';measureHost.setAttribute('aria-hidden','true');document.body.append(measureHost);}
    measureHost.style.cssText=`position:absolute;visibility:hidden;pointer-events:none;left:-20000px;top:0;width:${n.w}px;font-family:"${n.font}";font-size:${n.fontSize}px;font-weight:${n.bold?700:400};font-style:${n.italic?'italic':'normal'};letter-spacing:${n.letterSpacing||0}px;line-height:${n.lineHeight||1.5};overflow-wrap:anywhere;--report-accent:${n.accent};color:${n.color};text-align:${n.align};`;
    measureHost.innerHTML=html;return Math.ceil(measureHost.getBoundingClientRect().height)+2;
  }
  function paginate(doc,override,mode='values',measure=browserMeasure,options={}){
    const ctx=context(doc,override),size=M.pageSize(doc),settings=doc.pagination||{},sheet=M.normalizeSheet(doc.sheet,true),margin=Math.max(M.clamp(Number(settings.margin)||44,24,90),sheet.frame.enabled?sheet.frame.inset+sheet.frame.width+6:0),gap=Number(settings.gap)||14,bottom=Math.min(size.h-margin,D.bottom(doc,ctx,measure));
    ctx.fields.page='88888';ctx.fields.pages='888';
    if(bottom<=margin+80)throw new Error('El pie de página ocupa demasiado espacio. Reduce su tamaño de letra, la cantidad de texto o la distancia desde abajo.');
    const result=[];let page,y,sourceIndex=0,repeatNode,repeatHeight=0;
    const bounded=n=>options.freeX?({...n,w:Math.min(n.w,size.w),x:M.clamp(n.x,0,size.w-Math.min(n.w,size.w))}):({...n,w:Math.min(n.w,size.w-2*margin),x:M.clamp(n.x,margin,size.w-margin-Math.min(n.w,size.w-2*margin))});
    const add=(n,html,h,continuation=false)=>{page.blocks.push({...n,y,h,_html:html,_flow:true,_continuation:continuation});y+=h+gap;};
    function newPage(repeat=true){if(result.length>=300)throw new Error('La muestra supera las 300 páginas. Reduce filas, texto o tamaño de letra.');page={sourceIndex,blocks:[]};result.push(page);y=margin;if(repeat&&settings.repeatHeader!==false&&repeatNode){add(repeatNode,content(repeatNode,doc,ctx,mode),repeatHeight,true);}if(result.length===1&&Number.isFinite(options.firstY))y=Math.max(0,options.firstY);}
    function cap(){return bottom-margin-(settings.repeatHeader!==false&&repeatNode?repeatHeight+gap:0);}
    function moveIfNeeded(h){if(y+h>bottom&&(page.blocks.length||y>margin+2))newPage();}
    function rowHeight(u,parts,n){return Math.max(1,measure(tableHTML(parts,[u],ctx,false),n)-2);}
    function splitOversize(unit,parts,n,max){
      if(unit.kind!=='row'||(!parts.table.indicatorsInSubject&&!parts.columns.some(c=>c.key==='indicators')))return [unit];
      if(rowHeight(unit,parts,n)<=max)return [unit];
      const chunks=[],remaining=unit.indicators.map(i=>({...i}));let part=0;
      while(remaining.length){let count=0;for(let end=1;end<=remaining.length;end++){if(rowHeight({...unit,indicators:remaining.slice(0,end),part},parts,n)>max)break;count=end;}
        if(!count){const item=remaining[0],words=item.text.match(/\S+\s*/g)||[''];let low=1,high=words.length,best=0;while(low<=high){const mid=Math.floor((low+high)/2),candidate={...unit,part,indicators:[{...item,text:words.slice(0,mid).join('')}]};if(rowHeight(candidate,parts,n)<=max){best=mid;low=mid+1;}else high=mid-1;}if(!best)throw new Error('La columna es demasiado estrecha para este texto. Aumenta su ancho o reduce la letra.');const first={...item,key:item.key+'-p'+part,text:words.slice(0,best).join('')};const rest=words.slice(best).join('');chunks.push({...unit,part:part++,indicators:[first]});if(rest)remaining[0]={...item,text:rest};else remaining.shift();}
        else chunks.push({...unit,part:part++,indicators:remaining.splice(0,count)});
      }return chunks;
    }
    const firstHeader=options.repeatHeader||repeatingHeader(doc);
    for(const [si,source] of doc.pages.entries()){
      sourceIndex=si;repeatNode=(repeatingHeader(doc,source.blocks)||firstHeader);if(repeatNode){repeatNode=bounded(repeatNode);repeatHeight=measure(content(repeatNode,doc,ctx,mode),repeatNode);if(repeatHeight>(bottom-margin)/2)throw new Error('Reduce el encabezado: debe ocupar menos de media página.');}
      const ownHeader=source.blocks.some(n=>(n.type==='header'||n.headerPart)&&!n.hidden);newPage(si>0&&!ownHeader);
      for(const original of source.blocks){
        if(original.hidden)continue;const n=bounded(original);
        if(n.type==='indicators'){n.type='table';n.table={...M.tableDefaults(),columns:[{key:'subject',label:'Asignatura',width:30},{key:'indicators',label:'Indicadores',width:70}]};}
        if(n.type==='pagebreak'){if(page.blocks.length)newPage();continue;}
        if(n.type==='table'||n.type==='grades'){
          const parts=tableParts(n,doc,ctx),headHeight=measure(tableHTML(parts,[],ctx,true),n),groupHeight=parts.table.groupBy==='area'?rowHeight({kind:'group',group:'Dimensión',label:'Dimensión'},parts,n):0;
          let units=parts.units.flatMap(u=>splitOversize(u,parts,n,cap()-headHeight-groupHeight-8));let activeGroup='',first=true;
          if(!units.length){const html='<div class="empty-report-table">No hay materias seleccionadas para esta tabla.</div>',h=measure(html,n);moveIfNeeded(h);add(n,html,h);continue;}
          while(units.length){
            const showHead=first||parts.table.repeatHead,prefix=units[0].kind==='row'&&activeGroup&&units[0].group===activeGroup&&!units[0].row?.isArea&&parts.table.groupBy==='area'?[{kind:'group',group:activeGroup,continuation:true}]:[];
            let used=showHead?headHeight:2,chosen=[];for(const p of prefix)used+=rowHeight(p,parts,n);
            for(let i=0;i<units.length;i++){const u=units[i],height=rowHeight(u,parts,n),next=(u.kind==='group'||u.row?.isArea&&parts.table.rowMode==='areas-subjects')&&units[i+1]?rowHeight(units[i+1],parts,n):0;if(y+used+height+next+4>bottom)break;chosen.push(u);used+=height;}
            if(!chosen.length){if(y>margin+(settings.repeatHeader!==false&&repeatNode?repeatHeight+gap:0)+2){newPage();continue;}throw new Error('El contenido de una fila no cabe. Reduce la letra o aumenta el ancho de la tabla.');}
            const chunk=[...prefix,...chosen],html=tableHTML(parts,chunk,ctx,showHead),height=measure(html,n);if(y+height>bottom+2){if(chosen.length>1){chosen.pop();if(chosen.at(-1)?.kind==='group'||chosen.at(-1)?.row?.isArea&&parts.table.rowMode==='areas-subjects')chosen.pop();}if(!chosen.length){newPage();continue;}}
            const finalChunk=[...prefix,...chosen],fragment=tableHTML(parts,finalChunk,ctx,showHead);add(n,fragment,measure(fragment,n),!first);for(const u of chosen)if(u.group)activeGroup=u.group;units=units.slice(chosen.length);first=false;if(units.length)newPage();
          }continue;
        }
        let html=content(n,doc,ctx,mode),height=['image','rectangle','circle','line'].includes(n.type)?n.h:measure(html,n);
        if(n.keepWithNext)moveIfNeeded(height+60);
        if(height<=cap()){moveIfNeeded(height);add(n,html,height);continue;}
        if(['text','title','observation','field'].includes(n.type)){
          let raw=n.type==='field'?'{{'+n.field+'}}':n.text||'{{observation}}';const tokens=raw.match(/\{\{[a-zA-Z][a-zA-Z0-9_]*\}\}|\s+|[^\s{}]+|[{}]/g)||[];let continuation=false;
          while(tokens.length){let low=1,high=tokens.length,best=0;while(low<=high){const mid=Math.floor((low+high)/2),candidate=content({...n,type:'text',text:tokens.slice(0,mid).join('')},doc,ctx,mode);if(y+measure(candidate,n)<=bottom){best=mid;low=mid+1;}else high=mid-1;}if(!best){if(y>margin+repeatHeight+gap){newPage();continue;}throw new Error('El texto no cabe con ese ancho y tamaño de letra.');}const fragment=content({...n,type:'text',text:tokens.splice(0,best).join('')},doc,ctx,mode);add(n,fragment,measure(fragment,n),continuation);continuation=true;if(tokens.length)newPage();}
        }else{height=Math.min(height,cap());moveIfNeeded(height);add(n,html,height);}
      }
    }
    if(mode!=='fields')for(const [i,p] of result.entries())for(const n of p.blocks)n._html=n._html.replace(/(<span[^>]*data-bound-field="(page|pages)"[^>]*>)[\s\S]*?(<\/span>)/g,(_,open,key,close)=>open+(key==='page'?sheet.numbering.start+i:result.length)+close);
    ctx.fields.page=String(sheet.numbering.start);ctx.fields.pages=String(result.length);
    return {pages:result,ctx,margin,bottom};
  }
  function reference(kind){
    const visual=kind==='reference-preschool',final=kind==='reference-final',doc=M.documentDesign('blank',final?'final':'periodo');
    doc.schema=2;doc.layout='flow';doc.name=visual?'Valoración visual · Agrupada':final?'Informe anual · Detallado':'Asignaturas · Intensidad horaria';doc.school='Colegio de muestra';doc.sampleScale=visual?'visual':'numeric';doc.sample={profile:visual?'preschool':'primary',student:'one',year:final?'2025':'2026',periods:4,count:visual?8:14,length:'normal',period:3};doc.pagination={enabled:true,margin:final?32:44,gap:final?9:14,repeatHeader:true};if(final)doc.format='a4';
    const b=(type,options={})=>M.block(type,final?32:44,0,{w:final?730:728,fontSize:visual?13:final?10.5:12,accent:'#183C67',padding:0,...options});
    const table=M.tableDefaults();table.columns=visual?[{key:'subject',label:'Materia e indicadores',width:70},{key:'grade',label:'Valoración',width:30}]:[{key:'subject',label:'Asignatura',width:45},{key:'hours',label:'I.H.',width:7},...(final?[{key:'periods',label:'P',width:28}]:[]),{key:'grade',label:final?'Nota final':'Valoración',width:12},{key:'performance',label:'Desempeño',width:18}];table.groupBy=visual?'area':'none';table.indicatorsInSubject=visual;table.density=final?'compact':'normal';
    doc.pages[0].blocks=[b('header',{fontSize:16,repeat:true}),b('title',{text:final?'INFORME ACADÉMICO FINAL · {{year}}':'INFORME DESCRIPTIVO · {{period}} · {{year}}',fontSize:15}),...(final?[b('text',{text:'El estudiante {{student}} {{promotionVerb}} en esta institución el grado {{grade}} de Educación {{level}} durante el año lectivo {{year}}, alcanzando los logros propuestos y presentando el siguiente registro de valoración escolar.'})]:[b('text',{text:'NOMBRE: {{student}}',bold:true,fontSize:15})]),b('scale'),b('text',{text:'Materias y valoraciones',bold:true,fontSize:15,keepWithNext:true}),b('table',{table}),...(final?[b('promotion')]:[b('text',{text:'Promedio en el período: {{average}}',bold:true}),b('attendance'),b('observation',{text:'{{observation}}'})]),b('signature')];
    return doc;
  }
  const api={headerLines,repeatingHeader,browserMeasure,areaResults,escape,numeric,performance,faces,context,text,tableConfig,expandedColumns,tableParts,rowHTML,tableHTML,content,paginate,reference};root.ReportPrototypeEngine=api;if(typeof module!=='undefined'&&module.exports)module.exports=api;
})(typeof window==='undefined'?globalThis:window);
