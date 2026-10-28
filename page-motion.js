/* Shared prototype transitions; no authentication or credential persistence. */
(() => {
 const reduced=matchMedia('(prefers-reduced-motion: reduce)');
 const colors=['#648b49','#d9b350','#be5350','#87619b','#4c85a7'];
 let busy=false,overlay;
 function curtain(){
  overlay=document.createElement('div');overlay.setAttribute('aria-hidden','true');
  Object.assign(overlay.style,{position:'fixed',inset:'0',zIndex:'9999',pointerEvents:'none',overflow:'hidden'});
  colors.forEach((color,index)=>{const band=document.createElement('div');Object.assign(band.style,{position:'absolute',left:'0',top:`${index*20}%`,width:'100%',height:'20.1%',background:document.documentElement.dataset.theme==='dark'?'#211f1b':'#f6f1e6',borderRight:`5px solid ${color}`,boxSizing:'border-box'});overlay.append(band)});
  document.body.append(overlay);return [...overlay.children];
 }
 window.momentoNavigate=async href=>{
  if(busy)return;busy=true;
  const url=new URL(href,location.href);url.searchParams.set('theme',document.documentElement.dataset.theme||'light');
  if(reduced.matches){location.assign(url);return}
  const bands=curtain();
  try{await Promise.all(bands.map((band,i)=>band.animate([{transform:'translateX(-100%)'},{transform:'translateX(0)'}],{duration:340,delay:i*24,fill:'forwards',easing:'cubic-bezier(.65,0,.35,1)'}).finished))}catch{}
  url.searchParams.set('entry','ribbon');location.assign(url);
 };
 document.addEventListener('click',event=>{
  const link=event.target.closest('a[href]');
  if(!link||event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||link.target||link.hasAttribute('download'))return;
  const url=new URL(link.href,location.href);
  if(url.origin===location.origin&&/\/(login|instant-memories-v2)\.html$/.test(url.pathname)){event.preventDefault();window.momentoNavigate(url)}
 });
 const url=new URL(location.href);
 if(url.searchParams.get('entry')==='ribbon'){
  url.searchParams.delete('entry');history.replaceState(null,'',url);
  if(!reduced.matches){const bands=curtain();Promise.all(bands.map((band,i)=>band.animate([{transform:'translateX(0)'},{transform:'translateX(100%)'}],{duration:480,delay:i*24,easing:'cubic-bezier(.22,1,.36,1)',fill:'forwards'}).finished)).finally(()=>overlay?.remove())}
 }
 window.addEventListener('pageshow',event=>{if(event.persisted){busy=false;overlay?.remove()}});
 reduced.addEventListener('change',()=>{if(reduced.matches)overlay?.remove()});
})();
