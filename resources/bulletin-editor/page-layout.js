(function(root){
  'use strict';
  const M=typeof module!=='undefined'&&module.exports?require('./layout-model.js'):root.ReportPrototypeModel;
  const F=typeof module!=='undefined'&&module.exports?require('./field-guide.js'):root.ReportFieldGuide;
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const resolve=(value,fields)=>esc(F.internalize(value).replace(/\{\{([a-zA-Z][a-zA-Z0-9_]*)\}\}/g,(_,key)=>fields[key]??'' )).replace(/\n/g,'<br>');
  const font=p=>`font-family:'${p.font}';font-size:${p.fontSize}px;color:${p.color};font-weight:${p.bold?700:400};font-style:${p.italic?'italic':'normal'};text-decoration:${p.underline?'underline':'none'};text-align:${p.align};line-height:1.5;`;
  const dimensions=p=>p.height==null?'':`height:${p.height}px;box-sizing:border-box;`;
  function transformBox(box,handle,dx,dy,size){
    if(!handle)return {...box,x:M.clamp(box.x+dx,0,size.w-box.w),y:M.clamp(box.y+dy,0,size.h-box.h)};
    const left=handle.includes('w')?M.clamp(box.x+dx,0,box.x+box.w-50):box.x;
    const top=handle.includes('n')?M.clamp(box.y+dy,0,box.y+box.h-22):box.y;
    const right=handle.includes('e')?M.clamp(box.x+box.w+dx,left+50,size.w):box.x+box.w;
    const bottom=handle.includes('s')?M.clamp(box.y+box.h+dy,top+22,size.h):box.y+box.h;
    return {x:left,y:top,w:right-left,h:bottom-top};
  }
  function alignBox(box,targets,size,enabled=true,contentOffset=null){
    if(!enabled)return {box:{...box},guides:[]};
    const next={...box,x:M.clamp(Math.round(box.x/4)*4,0,size.w-box.w),y:M.clamp(Math.round(box.y/4)*4,0,size.h-box.h)},guides=[];
    for(const [axis,length,limit] of [['x','w',size.w],['y','h',size.h]]){
      const matches=[];
      for(const target of targets){
        const textAlignment=axis==='y'&&contentOffset!==null&&Number.isFinite(target.textY);
        for(const point of textAlignment?[0]:[0,.5,1])for(const own of textAlignment?[0]:[0,.5,1]){
          const at=textAlignment?target.textY:target[axis]+target[length]*point;
          const delta=at-(box[axis]+(textAlignment?contentOffset:box[length]*own)),position=box[axis]+delta;
          if(Math.abs(delta)<=8&&position>=0&&position+box[length]<=limit)matches.push({delta,position,at,kind:textAlignment?'baseline':'edge'});
        }
      }
      const center=limit/2,delta=center-(box[axis]+box[length]/2),position=box[axis]+delta;
      if(Math.abs(delta)<=8&&position>=0&&position+box[length]<=limit)matches.push({delta,position,at:center,kind:'page'});
      matches.sort((a,b)=>Math.abs(a.delta)-Math.abs(b.delta));
      if(matches.length){next[axis]=matches[0].position;guides.push({axis,at:matches[0].at,kind:matches[0].kind});}
    }
    return {box:next,guides};
  }
  function geometry(doc){const sheet=M.normalizeSheet(doc.sheet,!!doc.pagination?.enabled),size=M.pageSize(doc);sheet.footer.left=Math.min(sheet.footer.left,size.w-50);sheet.numbering.right=Math.min(sheet.numbering.right,size.w-50);sheet.footer.bottom=Math.min(sheet.footer.bottom,size.h-24);sheet.numbering.bottom=Math.min(sheet.numbering.bottom,size.h-24);for(const part of [sheet.footer,sheet.numbering])if(part.height!==null)part.height=Math.min(part.height,size.h-part.bottom);const numberWidth=Math.max(50,Math.min(sheet.numbering.width,size.w-sheet.numbering.right-(sheet.numbering.free?0:16)));let available=size.w-sheet.footer.left-(sheet.footer.free?0:16);if(!sheet.footer.free&&sheet.numbering.enabled&&Math.abs(sheet.footer.bottom-sheet.numbering.bottom)<Math.max(sheet.footer.fontSize,sheet.numbering.fontSize)*2)available=Math.min(available,size.w-sheet.numbering.right-numberWidth-sheet.footer.left-12);return {sheet,size,footerWidth:Math.max(50,Math.min(sheet.footer.width,available)),numberWidth};}
  function render(doc,ctx,index,total,editable=false){const marker=editable?'<span class="sheet-baseline" aria-hidden="true" style="display:inline-block;width:0;height:0;padding:0;margin:0;border:0;vertical-align:baseline"></span>':'';
    const {sheet:s,size,footerWidth,numberWidth}=geometry(doc),fields={...ctx.fields,page:String(s.numbering.start+index),pages:String(total)},target=part=>editable?`data-sheet-target="${part}" tabindex="0" aria-label="Editar ${part==='frame'?'marco':part==='footer'?'pie de página':'numeración de páginas'}"`:'';
    return `<div class="sheet-background" style="background:${s.background}"></div>`+(s.frame.enabled&&s.frame.width?`<div class="sheet-frame" ${target('frame')} style="inset:${s.frame.inset}px;border:${s.frame.width}px ${s.frame.style} ${s.frame.color};border-radius:${s.frame.radius}px"></div>`:'')+(s.footer.enabled?`<div class="sheet-footer" ${target('footer')} style="left:${Math.min(s.footer.left,size.w-(s.footer.free?50:66))}px;bottom:${s.footer.bottom}px;width:${footerWidth}px;${font(s.footer)}${dimensions(s.footer)}${s.footer.rule.enabled?`border-top:${s.footer.rule.width}px ${s.footer.rule.style} ${s.footer.rule.color};padding-top:8px;`:''}"><span class="sheet-content">${marker}${resolve(s.footer.text,fields)}</span></div>`:'')+(s.numbering.enabled&&(!s.numbering.skipFirst||index)?`<div class="sheet-numbering" ${target('numbering')} style="right:${Math.min(s.numbering.right,size.w-(s.numbering.free?50:66))}px;bottom:${s.numbering.bottom}px;width:${numberWidth}px;${font(s.numbering)}${dimensions(s.numbering)}"><span class="sheet-content">${marker}${resolve(s.numbering.text,fields)}</span></div>`:'');
  }
  function bottom(doc,ctx,measure){const {sheet:s,size,footerWidth,numberWidth}=geometry(doc);let reserve=0;for(const [p,w,extra] of [[s.footer,footerWidth,s.footer.rule.enabled?10+s.footer.rule.width:0],[s.numbering,numberWidth,0]])if(p.enabled&&(!p.free||p.bottom<=size.h*.33)){const html=resolve(p.text,{...ctx.fields,page:'999',pages:'999'}),height=measure(`<div style="${font(p)}">${html}</div>`,{w,font:p.font,fontSize:p.fontSize,bold:p.bold,italic:p.italic,align:p.align,accent:p.color,color:p.color});reserve=Math.max(reserve,p.bottom+Math.max(height+extra,p.height||0)+9);}return size.h-reserve;}
  const api={render,bottom,geometry,resolve,font,dimensions,transformBox,alignBox};root.ReportPageLayout=api;if(typeof module!=='undefined'&&module.exports)module.exports=api;
})(typeof window==='undefined'?globalThis:window);
