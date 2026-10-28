/* Message routing is a local design simulation, not a backend access control. */
(() => {
 const contacts=[...people.map(p=>({...p,public:true})),{full:'Alex Morgan',public:true},{full:'Sam Rivera',public:true}];
 const face=id=>avatarPath(id%people.length);
 let recipientQuery='',draft='',attachments=[];
 const mediaURLs=new Set();
 function clearAttachments(){attachments.forEach(a=>{URL.revokeObjectURL(a.url);mediaURLs.delete(a.url)});attachments=[]}
 window.addEventListener('pagehide',e=>{if(!e.persisted)mediaURLs.forEach(url=>URL.revokeObjectURL(url))});
 const followsBack=new Set([0,1]);
 const allowsRequests=id=>id!==5;
 const threads=new Map([
  [0,{kind:'inbox',unread:2,messages:[{text:'That light at the lake was beautiful.',mine:false},{text:'Same time next weekend?',mine:false}]}],
  [1,{kind:'inbox',unread:0,messages:[{text:'I’ll bring my camera next time.',mine:true}]}],
  [3,{kind:'request',unread:1,messages:[{text:'Hi! Where was that lovely dinner spot?',mine:false}]}]
 ]);
 let section='inbox',query='',composing=false,recipient=null;
 const icon=(name)=>`<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${{compose:'<path d="M12 5H5v14h14v-7M14 4l6 6M10 14l2-6 6-6 4 4-6 6Z"/>',back:'<path d="m14 6-6 6 6 6M8 12h13"/>',search:'<circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/>',send:'<path d="m3 3 18 9-18 9 4-9-4-9ZM7 12h14"/>'}[name]}</svg>`;
 const route=id=>threads.get(id)?.kind==='inbox'?'inbox':following.has(id)&&followsBack.has(id)?'inbox':allowsRequests(id)?'outgoing':'blocked';
 const previousRender=render;
 render=function(view='feed',person=null){clearAttachments();document.body.classList.toggle('chat-mode',view==='chat');if(view==='chat'){composing=false;query='';if(person!==null&&threads.get(person)?.kind==='request')section='request';else section='inbox'}previousRender(view,person)};
 function draw(){
  const root=document.getElementById('view'),thread=threads.get(activeChat);
  if(activeChat!==null&&thread)thread.unread=0;
  const requests=[...threads.values()].filter(t=>t.kind==='request').length;
  const account=previewSettings.name==='Your name'?'Your moments':previewSettings.name;
  root.innerHTML=`<div class="dm-layout"><aside class="dm-rail" aria-label="Chat selection"><header class="dm-account"><strong>${escapeHTML(account)}</strong><button class="dm-icon" data-dm="compose" aria-label="New message">${icon('compose')}</button></header><label class="dm-search">${icon('search')}<span class="sr-only">Search chats</span><input id="dm-search" type="search" placeholder="Search chats" value="${escapeHTML(query)}"></label><div class="dm-heading">${section==='request'?`<button class="dm-icon" data-dm="inbox" aria-label="Back to chats">${icon('back')}</button><h2>Requests</h2>`:`<h2>Chats</h2><button class="dm-request-link" data-dm="requests">Requests <span>${requests||''}</span></button>`}</div><div class="dm-list"></div>${section==='request'?'<p class="dm-note">Accept a request to start a conversation.</p>':''}</aside><section class="dm-conversation" aria-label="Conversation">${composing?composer():activeChat===null?'<div class="dm-empty"><h2>A little conversation.</h2><p>Choose a chat or start a new message.</p><button class="message-button" data-dm="compose">New message</button></div>':conversation(thread)}</section></div>`;
  list();
  root.querySelector('#dm-search').addEventListener('input',e=>{query=e.target.value;list()});
  root.querySelector('#dm-recipient')?.addEventListener('input',e=>{recipientQuery=e.target.value;lookup()});
  if(composing){lookup();root.querySelector('#dm-text').value=draft;root.querySelector('#dm-text').addEventListener('input',e=>draft=e.target.value)}
  root.querySelector('#dm-form')?.addEventListener('submit',send);
  wireComposer();
 }
 function list(){document.querySelector('.dm-list').innerHTML=[...threads].filter(([id,t])=>(section==='request'?t.kind==='request':t.kind!=='request')&&contacts[id].full.toLowerCase().includes(query.toLowerCase())).map(([id,t])=>`<button class="dm-row ${activeChat===id?'selected':''}" data-thread="${id}" ${activeChat===id?'aria-current="true"':''}><img src="${face(id)}" alt=""><span><strong>${escapeHTML(contacts[id].full)}</strong><small>${t.kind==='outgoing'?'Request sent':escapeHTML(t.messages.at(-1)?.text||(t.messages.at(-1)?.attachments?.length?'Photo / video':''))}</small></span>${t.unread?`<span class="dm-unread" aria-label="${t.unread} unread messages">${t.unread}</span>`:''}</button>`).join('')||'<p class="dm-note">No conversations here yet.</p>'}
 function composer(){const pending=recipient!==null&&['request','outgoing'].includes(threads.get(recipient)?.kind);const state=recipient===null?null:route(recipient);return `<header class="dm-top"><h2>New message</h2></header><div class="dm-recipient-area">${recipient!==null?`<div class="dm-chosen"><img src="${face(recipient)}" alt=""><strong>${escapeHTML(contacts[recipient].full)}</strong><button type="button" data-dm="change-recipient">Change</button></div>`:`<label class="dm-search">${icon('search')}<span class="sr-only">Find a recipient</span><input id="dm-recipient" type="search" autocomplete="off" placeholder="Search people…" value="${escapeHTML(recipientQuery)}" aria-controls="dm-results"></label><div id="dm-results" aria-label="People matching your search"></div>`}</div><p class="dm-note">${pending?'A request is already pending. Open it from the chat rail.':state==='inbox'?'You follow each other, or this conversation has been accepted. Your message goes to Chats.':state==='blocked'?'This person is not accepting message requests.':state?'Your message will be sent as a request.':'Choose someone to write to.'}</p>${form(pending||state==='blocked'||recipient===null,state==='outgoing'?'Send request':'Send')}`}
 function lookup(){
  const target=document.getElementById('dm-results');if(!target)return;
  const normalize=value=>value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
  const query=normalize(recipientQuery);
  if(!query){target.innerHTML='<p class="dm-lookup-hint">Start typing a name to find someone.</p>';return}
  const terms=query.split(/\s+/);
  const matches=contacts.map((p,id)=>({...p,id,name:normalize(p.full)}))
   .filter(p=>(following.has(p.id)||p.public)&&terms.every(term=>p.name.includes(term)))
   .map(p=>({...p,rank:p.name===query?0:p.name.startsWith(query)?1:terms.every(term=>p.name.split(/\s+/).some(word=>word.startsWith(term)))?2:3}))
   .sort((a,b)=>Number(following.has(b.id))-Number(following.has(a.id))||a.rank-b.rank||a.full.localeCompare(b.full))
   .slice(0,5);
  target.innerHTML=matches.length?matches.map(p=>`<button type="button" class="dm-row" data-recipient="${p.id}"><img src="${face(p.id)}" alt=""><span><strong>${escapeHTML(p.full)}</strong><small>${following.has(p.id)?'Following':'Public profile · message request'}</small></span></button>`).join(''):'<p class="dm-lookup-hint" role="status">No matching profiles. Try another name.</p>';
 }

 function form(disabled=false,label='Send'){return `<form id="dm-form" class="dm-form"><div id="dm-attachments" class="dm-attachments"></div><div class="dm-input-shell"><button class="dm-attach" type="button" aria-label="Attach photos or videos" title="Attach photos or videos" ${disabled?'disabled':''}><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="m8 12 6-6a3 3 0 0 1 4 4l-8 8a5 5 0 0 1-7-7l8-8"/></svg></button><input id="dm-file" type="file" accept="image/*,video/*" multiple hidden ${disabled?'disabled':''}><label class="sr-only" for="dm-text">Message. Enter to send, Shift plus Enter for a new line.</label><textarea id="dm-text" maxlength="2000" rows="1" placeholder="Message…" ${disabled?'disabled':''}></textarea><button class="dm-send" type="submit" aria-label="${label}" title="${label} (Enter)" ${disabled?'disabled':''}>${icon('send')}</button></div></form>`}
 function attachmentPreview(){const target=document.getElementById('dm-attachments');if(!target)return;target.innerHTML=attachments.map((a,i)=>`<div class="dm-attachment"><span>${escapeHTML(a.name)}</span><button type="button" data-remove-media="${i}" aria-label="Remove ${escapeHTML(a.name)}">×</button></div>`).join('')}
 function wireComposer(){
  const field=document.getElementById('dm-text');if(!field)return;
  const resize=()=>{field.style.height='auto';field.style.height=Math.min(field.scrollHeight,90)+'px';field.style.overflowY=field.scrollHeight>90?'auto':'hidden'};
  field.addEventListener('input',resize);resize();attachmentPreview();
  field.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing&&e.keyCode!==229){e.preventDefault();field.form.requestSubmit()}});
  document.querySelector('.dm-attach').addEventListener('click',()=>document.getElementById('dm-file').click());
  document.getElementById('dm-file').addEventListener('change',e=>{
   for(const file of e.target.files){
    if(!/^(image|video)\//.test(file.type)){announce('Choose a photo or video.');continue}
    if(file.size>25*1024*1024||attachments.length>=4){announce('Preview limit: four files, up to 25 MB each.');continue}
    const url=URL.createObjectURL(file);mediaURLs.add(url);attachments.push({url,name:file.name,video:file.type.startsWith('video/')});
   }
   e.target.value='';attachmentPreview();
  });
 }
 function mediaHTML(m){return (m.attachments||[]).map(a=>a.video?`<video class="dm-media" src="${a.url}" controls preload="metadata" aria-label="${escapeHTML(a.name)}"></video>`:`<img class="dm-media" src="${a.url}" alt="${escapeHTML(a.name)}">`).join('')}

 function conversation(t){const p=contacts[activeChat];return `<header class="dm-top"><button class="dm-icon dm-mobile-back" data-dm="back" aria-label="Back to conversations">${icon('back')}</button><img src="${face(activeChat)}" alt="">${activeChat<people.length?profileLink(activeChat,p.full):`<strong>${escapeHTML(p.full)}</strong>`}</header><div class="dm-log" role="log" aria-label="Messages">${(t?.messages||[]).map(m=>`<div class="dm-bubble ${m.mine?'mine':''}">${mediaHTML(m)}${m.text?`<span>${escapeHTML(m.text)}</span>`:''}</div>`).join('')||'<p class="dm-note">Start with a little hello.</p>'}</div>${t?.kind==='request'?'<div class="dm-request-actions"><p>Accept this request to reply.</p><button class="message-button" data-dm="accept">Accept</button><button class="message-button" data-dm="decline">Decline</button></div>':t?.kind==='outgoing'?'<p class="dm-note">Request sent. You can continue after it is accepted.</p>':form(route(activeChat)==='blocked',route(activeChat)==='outgoing'?'Send request':'Send')}`}
 function send(e){e.preventDefault();const id=composing?recipient:activeChat,text=e.target.querySelector('textarea').value.trim();if(id===null||(!text&&!attachments.length))return;const kind=route(id);if(kind==='blocked'||threads.get(id)?.kind==='request'||threads.get(id)?.kind==='outgoing')return;const t=threads.get(id)||{kind,unread:0,messages:[]};t.messages.push({mine:true,text,attachments:[...attachments]});attachments=[];draft='';threads.set(id,t);activeChat=id;composing=false;section='inbox';draw();document.querySelector('.dm-log')?.scrollTo(0,99999);document.getElementById('dm-text')?.focus();announce(kind==='outgoing'?'Request added to preview. Not delivered.':'Message added to preview. Not delivered.')}
 document.addEventListener('click',e=>{
  const remove=e.target.closest('[data-remove-media]');if(remove){const [item]=attachments.splice(Number(remove.dataset.removeMedia),1);URL.revokeObjectURL(item.url);mediaURLs.delete(item.url);attachmentPreview();return}
  const pick=e.target.closest('[data-recipient]');if(pick){recipient=Number(pick.dataset.recipient);draw();document.getElementById('dm-text').focus();return}
  const row=e.target.closest('[data-thread]');if(row){clearAttachments();activeChat=Number(row.dataset.thread);composing=false;draw();return}
  const action=e.target.closest('[data-dm]')?.dataset.dm;if(!action)return;
  if(['compose','requests','inbox','back'].includes(action))clearAttachments();
  if(action==='compose'){composing=true;recipient=null;activeChat=null;recipientQuery='';draft=''}
  if(action==='change-recipient'){recipient=null;recipientQuery=''}
  if(action==='requests'||action==='inbox'){section=action==='requests'?'request':'inbox';activeChat=null;composing=false;query=''}
  if(action==='back'){activeChat=null;composing=false}
  if(action==='accept'){threads.get(activeChat).kind='inbox';section='inbox'}
  if(action==='decline'){threads.delete(activeChat);activeChat=null}
  draw();if(action==='compose'||action==='change-recipient')document.getElementById('dm-recipient').focus();
 });
 renderChat=function(){draw()};
 const observer=new MutationObserver(()=>document.body.classList.toggle('dm-thread-open',document.body.classList.contains('chat-mode')&&(activeChat!==null||composing)));observer.observe(document.getElementById('view'),{childList:true});
 readRoute();
})();
