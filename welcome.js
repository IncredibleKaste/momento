const themeButton=document.getElementById('theme');
function applyTheme(dark){document.documentElement.dataset.theme=dark?'dark':'light';themeButton.setAttribute('aria-label',`Switch to ${dark?'light':'dark'} theme`);document.querySelectorAll('[data-destination]').forEach(link=>{const url=new URL(link.href);url.searchParams.set('theme',dark?'dark':'light');link.href=url.href});const url=new URL(location.href);url.searchParams.set('theme',dark?'dark':'light');history.replaceState(null,'',url)}
applyTheme(new URLSearchParams(location.search).get('theme')==='dark');themeButton.addEventListener('click',()=>applyTheme(document.documentElement.dataset.theme!=='dark'));
const reduced=matchMedia('(prefers-reduced-motion: reduce)'),replay=document.getElementById('replay');function motionPreference(){replay.disabled=reduced.matches;replay.innerHTML=reduced.matches?'Reduced motion enabled':'<span aria-hidden="true">↺</span> Replay ribbon'}motionPreference();reduced.addEventListener('change',motionPreference);

let animations=[];
function finishIntro(){animations.forEach(animation=>animation.cancel());animations=[]}
function startIntro(){
 finishIntro();
 if(reduced.matches || document.hidden)return;
 const copy=document.querySelector('.copy'),ribbon=document.querySelector('.ribbon');
 if(!copy || !ribbon)return;
 const duration=1150;
 ribbon.querySelectorAll('i').forEach((strip,index)=>{
  const final=getComputedStyle(strip);
  animations.push(strip.animate([
   {transform:'translateX(-100%)'},
   {transform:final.transform}
  ],{duration,delay:index*45,easing:'cubic-bezier(.76,0,.24,1)',fill:'backwards'}));
 });
 const parts=[...copy.querySelectorAll('h1,.intro,.actions')];
 parts.forEach((part,index)=>animations.push(part.animate([
  // Keep the vertical mask outside the tight serif line box: descenders
  // (especially the p in Keep) must not wait for clip-path removal.
  {clipPath:'inset(-0.3em 100% -0.3em -0.08em)',transform:'translateY(3px)'},
  {clipPath:'inset(-0.3em -0.08em -0.3em -0.08em)',transform:'translateY(0)'}
 ],{duration:650,delay:260+index*65,fill:'backwards',easing:'cubic-bezier(.65,0,.25,1)'})));

}
startIntro();
document.addEventListener('visibilitychange',()=>{if(document.hidden)finishIntro()});
reduced.addEventListener('change',finishIntro);
document.querySelector('.copy')?.addEventListener('focusin',finishIntro);
window.addEventListener('resize',finishIntro);
replay.addEventListener('click',()=>{if(reduced.matches)return;startIntro()});
