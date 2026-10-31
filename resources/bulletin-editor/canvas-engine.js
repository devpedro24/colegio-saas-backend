/* Compact authoring canvas, with record-dependent expansion only at preview/print. */
(function(root){
  'use strict';
  const M=typeof module!=='undefined'&&module.exports?require('./layout-model.js'):root.ReportPrototypeModel;
  const R=typeof module!=='undefined'&&module.exports?require('./report-engine.js'):root.ReportPrototypeEngine;
  const D=typeof module!=='undefined'&&module.exports?require('./page-layout.js'):root.ReportPageLayout;
  const shape=n=>['image','rectangle','circle','line'].includes(n.type);
  function table(n,doc,mode='fields'){
    const ctx=R.context(doc),parts=R.tableParts(n,doc,ctx),t=parts.table,columns=parts.columns,count=doc.designer?.rows===1?1:2;
    if(mode==='values')return R.tableHTML(parts,parts.units.filter(u=>u.kind==='row').slice(0,count),ctx,true);
    const placeholders={subject:t.rowMode==='areas'?'Nombre del área':'Nombre de la asignatura',area:'Nombre del área',hours:'I.H.',grade:'Nota',performance:'Desempeño',indicators:'Indicadores de aprendizaje'};
    const units=Array.from({length:count},(_,i)=>{const area=t.rowMode==='areas'||t.rowMode==='areas-subjects'&&i===0,child=t.rowMode==='areas-subjects'&&i>0;return '<tr class="'+(area?'area-result':child?'area-subject':'')+'">'+columns.map(c=>{let label=c.period!==undefined?'P'+(c.period+1):c.key==='subject'&&area?'Nombre del área':placeholders[c.key]||c.label;return '<td class="column-'+c.key+'"><span class="template-placeholder">['+R.escape(label)+']</span>'+(c.key==='subject'&&t.indicatorsInSubject&&(!area||t.rowMode==='areas')?'<div class="template-indicator">[Indicadores de aprendizaje]</div>':'')+'</td>';}).join('')+'</tr>';});
    const html=R.tableHTML(parts,[],ctx,true);return html.replace('<tbody></tbody>','<tbody>'+units.join('')+'</tbody>');
  }
  function fragment(n,doc,mode='fields'){
    if(['table','grades'].includes(n.type))return table(n,doc,mode);
    if(n.type==='pagebreak')return '<div class="design-break">Salto de página</div>';
    if(n.type==='indicators'&&mode==='values'){const ctx=R.context(doc);return R.content(n,doc,{...ctx,rows:ctx.rows.slice(0,doc.designer?.rows===1?1:2).map(row=>({...row,indicators:row.indicators.slice(0,2)}))},mode);}
    if(n.type==='indicators')return '<div class="template-indicator-block"><strong>[Nombre de la asignatura]</strong><p>[Indicadores de aprendizaje]</p></div>';
    return R.content(n,doc,R.context(doc),mode);
  }
  // Fitting is explicit after creating/editing text; manual sizing and record expansion stay intact.
  function fitText(n,doc,measure=R.browserMeasure,mode='fields'){
    if(shape(n)||['table','grades','indicators','pagebreak'].includes(n.type))return n;
    const size=M.pageSize(doc),height=measure(fragment(n,doc,mode),n);
    if(Number.isFinite(height))n.h=M.clamp(Math.ceil(height),12,size.h-16);
    return n;
  }
  function designOne(doc,mode='fields',measure=R.browserMeasure,modes={}){
    const size=M.pageSize(doc),margin=doc.pagination?.margin||44,bottom=Math.min(size.h-margin,D.bottom(doc,R.context(doc),measure)),pages=[];
    for(const [sourceIndex,source] of doc.pages.entries()){
      const prior=[],base=pages.length;pages.push({sourceIndex,blocks:[]});
      for(const n of source.blocks.map((node,z)=>({...node,_z:z})).filter(n=>n.type!=='pagebreak').sort((a,b)=>a.y-b.y||a.x-b.x||a._z-b._z)){
        const html=fragment(n,doc,modes[n.id]||mode),height=shape(n)?n.h:Math.max(n.h,Math.min(size.h,measure(html,n)));let top=n.y;
        if(!n.hidden&&!shape(n))for(const p of prior)if(follows(n,p.source))top=Math.max(top,p.bottom+n.y-p.source.y-p.source.h);
        let offset=Math.floor(top/size.h),y=top-offset*size.h;
        if(!shape(n)&&y+height>bottom&&height<=bottom-margin){offset++;y=margin;top=offset*size.h+y;}
        while(pages.length<=base+offset)pages.push({sourceIndex,blocks:[]});
        pages[base+offset].blocks.push({...n,y,h:height,_designer:true,_html:html});
        if(!n.hidden&&!shape(n))prior.push({source:n,bottom:top+height});
      }
      for(const page of pages.slice(base))page.blocks.sort((a,b)=>a._z-b._z);
    }return {pages};
  }
  function prepareOne(input,measure=R.browserMeasure){
    const doc=M.clone(input);for(const p of doc.pages)for(const n of p.blocks)if(['table','grades'].includes(n.type)){n.table=R.tableConfig(n,doc);n.table.filterSubjects=false;n.table.subjectKeys=[];}doc.designer={rows:doc.designer?.rows===1?1:2};
    if(doc.layout==='canvas')return doc;
    const automatic=doc.layout==='flow'||doc.pagination?.enabled,settings=doc.pagination||{},margin=settings.margin||44,gap=settings.gap||14,size=M.pageSize(doc),bottom=Math.min(size.h-margin,D.bottom(doc,R.context(doc),measure)),pages=[];
    for(const source of doc.pages){let page={...source,blocks:[]},y=margin;pages.push(page);for(const original of source.blocks){const n=M.clone(original);if(n.hidden){page.blocks.push(n);continue;}if(automatic&&n.type==='pagebreak'){page={...source,id:M.uid(),blocks:[]};pages.push(page);y=margin;continue;}if(automatic){n.h=shape(n)?n.h:Math.max(18,measure(fragment(n,doc),n));if(y+n.h>bottom&&page.blocks.length){page={...source,id:M.uid(),blocks:[]};pages.push(page);y=margin;}n.y=y;n.h=Math.min(n.h,size.h-margin*2);y+=n.h+gap;}M.constrain(n,size);page.blocks.push(n);}}
    doc.pages=pages.slice(0,12);if(pages.length>12)throw new Error('El diseño necesita más de doce hojas. Reduce los bloques de muestra.');doc.layout='canvas';doc.schema=2;doc.pagination={enabled:true,margin,gap,repeatHeader:settings.repeatHeader!==false};return doc;
  }
  const overlaps=(a,b)=>a.x<b.x+b.w-.5&&a.x+a.w>b.x+.5;
  // Only originally stacked, horizontally overlapping blocks follow one another.
  // Deliberate overlaps, shapes and side-by-side columns retain their free layout.
  const follows=(node,prior)=>node.y>=prior.y+prior.h-1&&overlaps(node,prior)&&!(node.groupKey&&node.groupKey===prior.groupKey);
  function emitOne(doc,override,mode='values',measure=R.browserMeasure,fallbackHeader){
    if(doc.layout!=='canvas')return R.paginate(doc,override,mode,measure);
    const size=M.pageSize(doc),ctx=R.context(doc,override),margin=doc.pagination?.margin||44,gap=doc.pagination?.gap||14,bottom=Math.min(size.h-margin,D.bottom(doc,ctx,measure)),pages=[],firstHeader=R.repeatingHeader(doc)||fallbackHeader;let base=0;
    function page(index,sourceIndex,header,repeat){if(index>=300)throw new Error('El boletín supera las 300 páginas.');while(pages.length<=index)pages.push({sourceIndex,blocks:[]});const p=pages[index];if(repeat&&header&&!p.blocks.some(n=>n.id===header.id)){const html=R.content(header,doc,ctx,mode);p.blocks.push({...header,y:margin,h:measure(html,header),_html:html,_flow:true,_continuation:true,_z:-1});}return p;}
    for(const [sourceIndex,source] of doc.pages.entries()){
      const header=doc.pagination?.repeatHeader!==false?(R.repeatingHeader(doc,source.blocks)||firstHeader):null,headerHeight=header?measure(R.content(header,doc,ctx,mode),header)+gap:0,prior=[];let last=base;
      page(base,sourceIndex,null,false);
      const ordered=source.blocks.map((n,z)=>({...n,_z:z})).filter(n=>!n.hidden).sort((a,b)=>a.y-b.y||a.x-b.x||a._z-b._z);
      for(const n of ordered){
        if(n.type==='pagebreak')continue;
        if(shape(n)){page(base,sourceIndex,null,false).blocks.push({...n,_html:R.content(n,doc,ctx,mode),_flow:true});continue;}
        const designHeight=n.h;let top=base*size.h+n.y;
        for(const p of prior)if(follows(n,p.source))top=Math.max(top,p.bottom+(n.y-p.source.y-p.designHeight));
        let index=Math.max(base,Math.floor(top/size.h)),y=top-index*size.h;if(index>base)y=Math.max(y,margin+headerHeight);if(y>=bottom-16){index++;y=margin+headerHeight;}
        const temporary={...doc,layout:'flow',pages:[{id:source.id,blocks:[n]}]},layout=R.paginate(temporary,override,mode,measure,{firstY:y,repeatHeader:n.id===header?.id?null:header,freeX:true});
        let actualBottom=index*size.h+y;
        for(const [i,chunk] of layout.pages.entries()){if(!chunk.blocks.length)continue;const target=page(index+i,sourceIndex,header,index+i>base);for(const b of chunk.blocks){if(b.id===header?.id&&n.id!==header.id){if(!target.blocks.some(old=>old.id===b.id))target.blocks.push({...b,_z:-1});continue;}target.blocks.push({...b,_z:n._z});actualBottom=Math.max(actualBottom,(index+i)*size.h+b.y+b.h);}last=Math.max(last,index+i);}
        prior.push({source:n,designHeight,bottom:actualBottom});
      }
      base=last+1;
    }
    const start=M.normalizeSheet(doc.sheet,true).numbering.start;
    for(const [i,p] of pages.entries()){p.blocks.sort((a,b)=>(a._z??0)-(b._z??0));if(mode!=='fields')for(const n of p.blocks)n._html=n._html.replace(/(<span[^>]*data-bound-field="(page|pages)"[^>]*>)[\s\S]*?(<\/span>)/g,(_,open,key,close)=>open+(key==='page'?start+i:pages.length)+close);}
    ctx.fields.page=String(start);ctx.fields.pages=String(pages.length);return {pages,ctx,margin,bottom};
  }
  function prepare(input,measure=R.browserMeasure){
    const doc=M.clone(input),pages=[];
    for(const source of doc.pages){const view=M.pageView(doc,source),part=prepareOne({...view,pages:[source]},measure);pages.push(...part.pages);}
    if(pages.length>12)throw new Error('El diseño necesita más de doce hojas. Reduce los bloques de muestra.');
    doc.pages=pages;doc.layout='canvas';doc.schema=2;doc.designer={rows:doc.designer?.rows===1?1:2};doc.pagination={enabled:true,margin:44,gap:14,repeatHeader:true,...doc.pagination};return doc;
  }
  function design(doc,mode='fields',measure=R.browserMeasure,modes={}){
    const pages=[];for(const [sourceIndex,source] of doc.pages.entries()){
      const view=M.pageView(doc,source),part=designOne({...view,pages:[source]},mode,measure,modes);
      for(const page of part.pages)pages.push({...page,sourceIndex,title:source.title||'',hidden:!!source.hidden,locked:!!source.locked,format:view.format,orientation:view.orientation,size:M.pageSize(view)});
    }return {pages};
  }
  function emit(doc,override,mode='values',measure=R.browserMeasure){
    const pages=[],visible=doc.pages.filter(p=>!p.hidden),fallback=R.repeatingHeader({...doc,pages:visible});let ctx=R.context(doc,override),margin=44,bottom=0;
    for(const [sourceIndex,source] of doc.pages.entries()){
      if(source.hidden)continue;const view=M.pageView(doc,source),part=emitOne({...view,pages:[source]},override,mode,measure,fallback);ctx=part.ctx;margin=part.margin;bottom=part.bottom;
      for(const page of part.pages)pages.push({...page,sourceIndex,format:view.format,orientation:view.orientation,size:M.pageSize(view)});
    }
    if(!pages.length)throw new Error('Muestra al menos una página antes de generar el boletín.');
    const start=M.normalizeSheet(doc.sheet,true).numbering.start;
    for(const [i,page] of pages.entries())if(mode!=='fields')for(const n of page.blocks)n._html=n._html.replace(/(<span[^>]*data-bound-field="(page|pages)"[^>]*>)[\s\S]*?(<\/span>)/g,(_,open,key,close)=>open+(key==='page'?start+i:pages.length)+close);
    ctx.fields.page=String(start);ctx.fields.pages=String(pages.length);return {pages,ctx,margin,bottom};
  }
  const api={prepare,design,emit,fragment,table,overlaps,fitText};root.ReportCanvasEngine=api;if(typeof module!=='undefined'&&module.exports)module.exports=api;
})(typeof window==='undefined'?globalThis:window);
