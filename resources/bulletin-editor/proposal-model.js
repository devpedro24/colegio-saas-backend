/* Prototype-only preferences and friendly field labels. */
(function(root){
  'use strict';
  const M=root.ReportPrototypeModel||(typeof require==='function'?require('./model.js'):null);
  if(!M.proposalReady){
    Object.assign(M.fields,{nit:'NIT del colegio',dane:'Código DANE',resolution:'Resolución del colegio',address:'Dirección del colegio',phone:'Teléfono del colegio',email:'Correo del colegio',principal:'Nombre del rector o rectora'});
    M.fields.teacher='Nombre del docente';M.fields.average='Promedio del informe';M.fields.promotionVerb='Estado de aprobación';
    M.grades=['Párvulos','Prejardín','Jardín','Transición','Primero','Segundo','Tercero','Cuarto','Quinto','Sexto','Séptimo','Octavo','Noveno','Décimo','Undécimo'];
    M.educationInfo=grade=>{const i=M.grades.indexOf(grade);return {level:i<4?'Preescolar':i<9?'Básica Primaria':i<13?'Básica Secundaria':'Media',next:M.grades[i+1]||'Finalización de educación media',group:i<4?['PVA','PJA','JAA','TRA'][i]:String(i-3).padStart(2,'0')+'A'};};
    M.normalizeInstitution=(raw={})=>{const value={};for(const key of ['name','nit','dane','resolution','address','phone','email','principal','motto'])value[key]=typeof raw[key]==='string'?raw[key].trim().slice(0,key==='name'?120:180):'';if(typeof raw.scaleLegend==='string')value.scaleLegend=raw.scaleLegend.slice(0,2500);if(Array.isArray(raw.performanceRanges))value.performanceRanges=raw.performanceRanges.slice(0,8).filter(r=>r&&typeof r.nombre==='string'&&Number.isFinite(Number(r.desde))&&Number.isFinite(Number(r.hasta))).map(r=>({nombre:r.nombre.slice(0,60),desde:String(r.desde),hasta:String(r.hasta)}));value.name||='Colegio de muestra';value.primary=M.color(raw.primary)?raw.primary:'#6A23FF';value.logo=typeof raw.logo==='string'&&raw.logo.length<=1800000&&/^data:image\/(png|jpeg|webp);base64,[a-z\d+/=\s]+$/i.test(raw.logo)?raw.logo:'';return value;};
    M.normalizeDesign=(raw={})=>{const pick=(key,options,fallback)=>options.includes(raw[key])?raw[key]:fallback;return {previewScale:pick('previewScale',['numeric','visual'],'numeric'),family:pick('family',['institucional','franja','lateral','editorial','compacto','visual'],'institucional'),header:pick('header',['center','band','side','editorial','compact','playful'],'center'),table:pick('table',['grid','striped','minimal','soft'],'grid'),legend:pick('legend',['stacked','inline'],'stacked'),signature:pick('signature',['rector','docente','ambos','none'],'rector'),palette:typeof raw.palette==='string'?raw.palette.replace(/[^a-z-]/g,'').slice(0,30):'violeta',purpose:pick('purpose',['periodo','final','preescolar','descriptivo','areas','areasdetalle','areasindicadores','areasfinal'],'periodo'),headerInk:M.color(raw.headerInk)?raw.headerInk:'#ffffff',catalogKey:typeof raw.catalogKey==='string'&&/^[a-z-]{1,80}$/.test(raw.catalogKey)?raw.catalogKey:''};};
    const validate=M.validate;
    M.validate=input=>{const doc=validate(input);if(input.institution){doc.institution=M.normalizeInstitution(input.institution);doc.school=doc.institution.name;}if(input.design)doc.design=M.normalizeDesign(input.design);if(doc.sample&&M.grades.includes(input.sample?.grade))doc.sample.grade=input.sample.grade;return doc;};
    M.proposalReady=true;
  }
  if(typeof module!=='undefined'&&module.exports)module.exports=M;
})(typeof window==='undefined'?globalThis:window);
