<?php
// BACKUP - can be deleted. Original map.php has been updated.
session_start();
include "files/config.php";
include "files/auth.php";
$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
requireLogin();
$showDetails = isGreenTeam($user);

// Fetch all towns
$townsArr = [];
$res = $conn->query("SELECT id, name, population, side, x_coord, y_coord FROM towns ORDER BY id");
while ($r = $res->fetch_assoc()) {
    $townsArr[] = [
        'id' => (int)$r['id'],
        'name' => $r['name'],
        'population' => $showDetails ? (int)$r['population'] : 0,
        'side' => $r['side'],
        'x' => (float)$r['x_coord'],
        'y' => (float)$r['y_coord'],
        'resources' => []
    ];
}

// Only load resources for green team
if ($showDetails) {// Fetch resources per town
$res = $conn->query("
    SELECT tr.town_id, wp.resource_name, tr.stock 
    FROM town_resources tr 
    JOIN world_prices wp ON tr.resource_id = wp.id 
    ORDER BY tr.town_id, wp.resource_name
");
while ($r = $res->fetch_assoc()) {
    $tid = (int)$r['town_id'];
    for ($i = 0; $i < count($townsArr); $i++) {
        if ($townsArr[$i]['id'] === $tid) {
            $townsArr[$i]['resources'][] = ['name' => $r['resource_name'], 'stock' => (float)$r['stock']];
            break;
        }
    }
}
} // end if showDetails

// Fetch within-side distances only
$distArr = [];
$res = $conn->query("
    SELECT td.town_id_1, td.town_id_2, td.distance_km
    FROM town_distances td
    JOIN towns t1 ON td.town_id_1 = t1.id
    JOIN towns t2 ON td.town_id_2 = t2.id
    WHERE t1.side = t2.side AND td.town_id_1 < td.town_id_2
");
while ($r = $res->fetch_assoc()) {
    $distArr[] = [
        'from' => (int)$r['town_id_1'],
        'to' => (int)$r['town_id_2'],
        'km' => (float)$r['distance_km']
    ];
}

include "files/header.php";
include "files/sidebar.php";
?>
<style>
#map-wrapper{position:relative;height:75vh;min-height:500px;overflow:hidden;cursor:grab;background:#0d1a2a;border-radius:6px;}
#map-wrapper.dragging{cursor:grabbing;}
#map-svg{width:100%;height:100%;display:block;}
#map-tooltip{display:none;position:fixed;pointer-events:none;z-index:9999;background:rgba(13,26,42,0.94);border:1px solid rgba(200,195,180,0.15);border-radius:8px;padding:12px 16px;color:#e8e4d8;font-size:12px;max-width:300px;box-shadow:0 8px 32px rgba(0,0,0,0.6);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);}
#map-controls{position:absolute;top:12px;right:12px;display:flex;flex-direction:column;gap:4px;z-index:100;}
#map-controls button{width:36px;height:36px;border:1px solid rgba(200,195,180,0.15);border-radius:8px;background:rgba(13,26,42,0.85);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);color:#ccc;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s,color .2s;}
#map-controls button:hover{background:rgba(30,50,70,0.95);color:#fff;}
#map-legend{position:absolute;bottom:12px;left:12px;background:rgba(13,26,42,0.92);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border:1px solid rgba(200,195,180,0.12);border-radius:8px;padding:10px 14px;z-index:100;font-size:11px;color:#c8c4b8;}
#map-legend h6{margin:0 0 6px;font-size:10px;color:rgba(200,195,180,0.6);text-transform:uppercase;letter-spacing:1px;}
.legend-item{display:flex;align-items:center;gap:8px;margin:3px 0;}
.legend-swatch{width:14px;height:14px;border-radius:3px;flex-shrink:0;}
.town-marker{transition:transform .15s ease,filter .15s ease;}
a.town-link:hover .town-marker{filter:brightness(1.4) drop-shadow(0 0 3px currentColor);}
</style>
<!-- BEGIN #content -->
<div id="content" class="app-content">
  <h1 class="page-header">World Map</h1>
  <div class="card">
    <div class="card-body p-0">
      <div id="map-wrapper">
        <div id="map-tooltip"></div>
        <div id="map-controls">
          <button id="btn-zin" title="Zoom In">+</button>
          <button id="btn-zout" title="Zoom Out">−</button>
          <button id="btn-fit" title="Fit All">⊞</button>
          <button id="btn-passes" title="Center on Passes">⚔</button>
        </div>
        <div id="map-legend">
          <h6>Legend</h6>
          <div class="legend-item"><span class="legend-swatch" style="background:#4488ff;"></span>Blue Town</div>
          <div class="legend-item"><span class="legend-swatch" style="background:#ff5555;"></span>Red Town</div>
          <div class="legend-item"><span class="legend-swatch" style="background:#4488ff;border:2px solid rgba(255,255,255,0.9);border-radius:2px;"></span>Customs House</div>
          <div class="legend-item"><span class="legend-swatch" style="background:#b8883a;height:3px;border-radius:1px;"></span>Major Road</div>
          <div class="legend-item"><span class="legend-swatch" style="background:#1a3018;"></span>Forest</div>
          <div class="legend-item"><span class="legend-swatch" style="background:linear-gradient(180deg,#5c5c58,#444838,#364830);"></span>Mountain Elevation</div>
          <div class="legend-item"><span class="legend-swatch" style="background:#2a6090;"></span>Water / River</div>
          <div class="legend-item"><span class="legend-swatch" style="background:repeating-linear-gradient(0deg,rgba(40,100,140,0.15),rgba(40,100,140,0.15) 2px,transparent 2px,transparent 4px);border:1px solid rgba(40,100,140,0.3);"></span>Marshland</div>
          <div class="legend-item"><span class="legend-swatch" style="background:transparent;border:1px dashed rgba(200,160,60,0.4);"></span>Choke Point</div>
        </div>
        <svg id="map-svg" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
          <defs>
            <pattern id="tree-pat" width="2.5" height="2.5" patternUnits="userSpaceOnUse">
              <circle cx="0.8" cy="0.8" r="0.4" fill="rgba(26,48,24,0.6)"/>
              <circle cx="2.0" cy="1.8" r="0.35" fill="rgba(26,48,24,0.5)"/>
            </pattern>
            <pattern id="marsh-pat" width="4" height="3" patternUnits="userSpaceOnUse">
              <path d="M0,1 Q1,0 2,1 Q3,2 4,1" fill="none" stroke="rgba(40,100,140,0.12)" stroke-width="0.3"/>
              <path d="M0,2.5 Q1,1.5 2,2.5 Q3,3.5 4,2.5" fill="none" stroke="rgba(40,100,140,0.10)" stroke-width="0.25"/>
            </pattern>
            <pattern id="wave-pat" width="10" height="5" patternUnits="userSpaceOnUse">
              <path d="M0,2.5 Q2.5,1 5,2.5 Q7.5,4 10,2.5" fill="none" stroke="rgba(30,80,140,0.04)" stroke-width="0.2"/>
            </pattern>
            <radialGradient id="glow-blue" cx="50%" cy="50%" r="50%">
              <stop offset="0%" stop-color="rgba(68,136,255,0.5)"/>
              <stop offset="100%" stop-color="rgba(68,136,255,0)"/>
            </radialGradient>
            <radialGradient id="glow-red" cx="50%" cy="50%" r="50%">
              <stop offset="0%" stop-color="rgba(255,85,85,0.5)"/>
              <stop offset="100%" stop-color="rgba(255,85,85,0)"/>
            </radialGradient>
          </defs>
        </svg>
      </div>
    </div>
  </div>
</div>
<!-- END #content -->

<script>
(function(){
const towns = <?= json_encode($townsArr) ?>;
const distances = <?= json_encode($distArr) ?>;
const svg = document.getElementById('map-svg');
const tooltip = document.getElementById('map-tooltip');
const wrapper = document.getElementById('map-wrapper');
const townMap = {};
towns.forEach(t => townMap[t.id] = t);

/* ── viewBox state ── */
const FULL = {x:-100,y:-60,w:400,h:200};
let vb = {x:10,y:-5,w:110,h:0};
function updateAspect(){
  const r = wrapper.getBoundingClientRect();
  vb.h = vb.w * (r.height / r.width);
  applyVB();
}
function applyVB(){
  svg.setAttribute('viewBox',`${vb.x} ${vb.y} ${vb.w} ${vb.h}`);
}
updateAspect();
window.addEventListener('resize', updateAspect);

/* ── Pan & Zoom ── */
let drag = false, dragStart = {x:0,y:0}, vbStart = {x:0,y:0};
function svgScale(){ return vb.w / wrapper.getBoundingClientRect().width; }

wrapper.addEventListener('mousedown', e=>{
  if(e.target.closest('.town-link') || e.target.closest('#map-controls') || e.target.closest('#map-legend')) return;
  drag=true; dragStart={x:e.clientX,y:e.clientY}; vbStart={x:vb.x,y:vb.y};
  wrapper.classList.add('dragging');
});
window.addEventListener('mousemove', e=>{
  if(!drag) return;
  const s=svgScale();
  vb.x = vbStart.x - (e.clientX - dragStart.x)*s;
  vb.y = vbStart.y - (e.clientY - dragStart.y)*s;
  applyVB();
});
window.addEventListener('mouseup', ()=>{ drag=false; wrapper.classList.remove('dragging'); });

wrapper.addEventListener('wheel', e=>{
  e.preventDefault();
  const rect = wrapper.getBoundingClientRect();
  const mx = (e.clientX - rect.left)/rect.width;
  const my = (e.clientY - rect.top)/rect.height;
  const factor = e.deltaY > 0 ? 1.12 : 1/1.12;
  const nw = Math.max(20, Math.min(FULL.w, vb.w*factor));
  const nh = nw * (rect.height/rect.width);
  vb.x += (vb.w - nw)*mx;
  vb.y += (vb.h - nh)*my;
  vb.w = nw; vb.h = nh;
  applyVB();
}, {passive:false});

/* Touch support */
let touches0=null, touchVB0=null, touchDist0=0;
wrapper.addEventListener('touchstart', e=>{
  if(e.target.closest('.town-link')||e.target.closest('#map-controls')||e.target.closest('#map-legend')) return;
  if(e.touches.length===1){
    drag=true; const t=e.touches[0];
    dragStart={x:t.clientX,y:t.clientY}; vbStart={x:vb.x,y:vb.y};
    wrapper.classList.add('dragging');
  } else if(e.touches.length===2){
    drag=false;
    touches0=[{x:e.touches[0].clientX,y:e.touches[0].clientY},{x:e.touches[1].clientX,y:e.touches[1].clientY}];
    touchDist0=Math.hypot(touches0[1].x-touches0[0].x,touches0[1].y-touches0[0].y);
    touchVB0={x:vb.x,y:vb.y,w:vb.w,h:vb.h};
  }
},{passive:false});
wrapper.addEventListener('touchmove', e=>{
  e.preventDefault();
  if(e.touches.length===1 && drag){
    const t=e.touches[0], s=svgScale();
    vb.x = vbStart.x-(t.clientX-dragStart.x)*s;
    vb.y = vbStart.y-(t.clientY-dragStart.y)*s;
    applyVB();
  } else if(e.touches.length===2 && touches0){
    const t=[{x:e.touches[0].clientX,y:e.touches[0].clientY},{x:e.touches[1].clientX,y:e.touches[1].clientY}];
    const dist=Math.hypot(t[1].x-t[0].x,t[1].y-t[0].y);
    const factor=touchDist0/dist;
    const rect=wrapper.getBoundingClientRect();
    const nw=Math.max(20,Math.min(FULL.w,touchVB0.w*factor));
    const nh=nw*(rect.height/rect.width);
    const cmx=((t[0].x+t[1].x)/2-rect.left)/rect.width;
    const cmy=((t[0].y+t[1].y)/2-rect.top)/rect.height;
    vb.x=touchVB0.x+(touchVB0.w-nw)*cmx;
    vb.y=touchVB0.y+(touchVB0.h-nh)*cmy;
    vb.w=nw;vb.h=nh;
    applyVB();
  }
},{passive:false});
wrapper.addEventListener('touchend', ()=>{ drag=false; touches0=null; wrapper.classList.remove('dragging'); });

/* Zoom buttons */
function zoomBy(factor,cx,cy){
  const rect=wrapper.getBoundingClientRect();
  if(cx===undefined){cx=0.5;cy=0.5;}
  const nw=Math.max(20,Math.min(FULL.w,vb.w*factor));
  const nh=nw*(rect.height/rect.width);
  vb.x+=(vb.w-nw)*cx; vb.y+=(vb.h-nh)*cy;
  vb.w=nw;vb.h=nh; applyVB();
}
document.getElementById('btn-zin').addEventListener('click',()=>zoomBy(1/1.4));
document.getElementById('btn-zout').addEventListener('click',()=>zoomBy(1.4));
document.getElementById('btn-fit').addEventListener('click',()=>{
  const rect=wrapper.getBoundingClientRect();
  vb.x=FULL.x;vb.y=FULL.y;vb.w=FULL.w;vb.h=FULL.w*(rect.height/rect.width);applyVB();
});
document.getElementById('btn-passes').addEventListener('click',()=>{
  const rect=wrapper.getBoundingClientRect();
  vb.w=60;vb.h=vb.w*(rect.height/rect.width);
  vb.x=62-vb.w/2;vb.y=36-vb.h/2;applyVB();
});

/* ── Build SVG content ── */
const NS='http://www.w3.org/2000/svg';
const defs=svg.querySelector('defs');
let html='';

// 1. Ocean base
html+=`<rect x="${FULL.x}" y="${FULL.y}" width="${FULL.w}" height="${FULL.h}" fill="#0d1a2a"/>`;
html+=`<rect x="${FULL.x}" y="${FULL.y}" width="${FULL.w}" height="${FULL.h}" fill="url(#wave-pat)"/>`;

// 2. Continental shelf
html+=`<path d="M-75,-45 C-58,-54 -22,-49 15,-47 C48,-45 62,-52 92,-47 C125,-42 155,-52 190,-45 C222,-38 248,-46 260,-34 C274,-20 278,8 274,30 C268,52 278,72 268,92 C258,108 244,120 222,115 C192,122 158,116 128,122 C98,128 65,118 32,124 C0,130 -30,122 -50,112 C-68,103 -78,88 -84,70 C-90,52 -88,30 -85,12 C-82,-8 -78,-32 -75,-45 Z" fill="#12243a" stroke="none"/>`;

// 3. Landmass
const COAST='M-70,-40 C-55,-48 -20,-44 15,-42 C45,-40 60,-46 90,-42 C120,-38 150,-46 185,-40 C215,-34 240,-40 252,-30 C264,-18 268,5 264,25 C260,45 268,65 260,85 C252,100 238,112 218,108 C190,115 155,110 125,115 C95,120 65,112 35,118 C5,122 -25,115 -45,105 C-62,97 -72,82 -78,65 C-84,48 -82,28 -80,10 C-78,-8 -74,-28 -70,-40 Z';
html+=`<path d="${COAST}" fill="#243828" stroke="rgba(100,140,180,0.15)" stroke-width="0.4"/>`;

// 4. Land clip path
html+=`<clipPath id="land-clip"><path d="${COAST}"/></clipPath>`;
html+=`<g clip-path="url(#land-clip)">`;

// 18. Grid reference lines (rendered early, behind everything)
for(let gx=-80;gx<=260;gx+=20){
  html+=`<line x1="${gx}" y1="${FULL.y}" x2="${gx}" y2="${FULL.y+FULL.h}" stroke="rgba(100,140,180,0.04)" stroke-width="0.15"/>`;
}
for(let gy=-40;gy<=120;gy+=20){
  html+=`<line x1="${FULL.x}" y1="${gy}" x2="${FULL.x+FULL.w}" y2="${gy}" stroke="rgba(100,140,180,0.04)" stroke-width="0.15"/>`;
}

// 5. Territory tinting
html+=`<rect x="${FULL.x}" y="${FULL.y}" width="${62-FULL.x}" height="${FULL.h}" fill="rgba(40,80,200,0.04)"/>`;
html+=`<rect x="62" y="${FULL.y}" width="${FULL.x+FULL.w-62}" height="${FULL.h}" fill="rgba(200,40,40,0.04)"/>`;

// 6. Foothill elevation band
html+=`<ellipse cx="62" cy="35" rx="35" ry="82" fill="#2c4228" stroke="rgba(140,110,70,0.08)" stroke-width="0.2"/>`;

// 7. Mountain elevation zones
const mtSegments=[
  {cx:62,cy:-16,rxBase:22,ryBase:28},
  {cx:62,cy:23,rxBase:14,ryBase:8},
  {cx:62,cy:49,rxBase:14,ryBase:8},
  {cx:62,cy:87,rxBase:22,ryBase:26}
];
const elevBands=[
  {frac:1.0,color:'#364830'},
  {frac:0.73,color:'#444838'},
  {frac:0.50,color:'#4c4840'},
  {frac:0.32,color:'#545048'},
  {frac:0.18,color:'#5c5c58'},
  {frac:0.09,color:'#707068'}
];
mtSegments.forEach(seg=>{
  elevBands.forEach(band=>{
    const rx=seg.rxBase*band.frac,ry=seg.ryBase*band.frac;
    if(rx<0.5||ry<0.5) return;
    html+=`<ellipse cx="${seg.cx}" cy="${seg.cy}" rx="${rx.toFixed(1)}" ry="${ry.toFixed(1)}" fill="${band.color}" stroke="rgba(140,110,70,0.12)" stroke-width="0.15"/>`;
  });
  // 8. Contour lines between bands
  for(let i=0;i<elevBands.length-1;i++){
    const f1=elevBands[i].frac,f2=elevBands[i+1].frac;
    for(let j=1;j<=3;j++){
      const f=f1-(f1-f2)*j/4;
      const rx=seg.rxBase*f,ry=seg.ryBase*f;
      if(rx<0.5||ry<0.5) continue;
      const isIndex=j===2;
      html+=`<ellipse cx="${seg.cx}" cy="${seg.cy}" rx="${rx.toFixed(1)}" ry="${ry.toFixed(1)}" fill="none" stroke="${isIndex?'rgba(140,110,70,0.22)':'rgba(140,110,70,0.10)'}" stroke-width="0.15"/>`;
    }
  }
});

// Snow cap accents on highest peaks
html+=`<ellipse cx="62" cy="-18" rx="1.5" ry="4" fill="#909088" opacity="0.35"/>`;
html+=`<ellipse cx="62" cy="85" rx="1.5" ry="3.5" fill="#909088" opacity="0.3"/>`;

// 9. Spot heights
const spotHeights=[
  {x:62,y:-18,label:'1,247m'},{x:62,y:23,label:'856m'},
  {x:62,y:49,label:'891m'},{x:62,y:85,label:'1,102m'}
];
spotHeights.forEach(s=>{
  html+=`<text x="${s.x}" y="${s.y-1}" fill="rgba(140,110,70,0.5)" font-size="1.2" text-anchor="middle" font-family="sans-serif">&#9650; ${s.label}</text>`;
});

// 10. Mountain range label
html+=`<text x="62" y="-45" fill="rgba(160,145,120,0.2)" font-size="3" text-anchor="middle" font-style="italic" font-family="serif">The Ironspine Mountains</text>`;

// 11. Forests
const FORESTS_NAMED=[
  {x:-20,y:20,rx:20,ry:14,name:'Darkwood'},
  {x:22,y:8,rx:12,ry:8,name:'Pine Ridge'},
  {x:120,y:14,rx:16,ry:11,name:'Ashwood'},
  {x:155,y:55,rx:18,ry:13,name:'Red Oak Forest'}
];
const FORESTS_SMALL=[
  {x:8,y:48,rx:10,ry:7},{x:-35,y:60,rx:8,ry:6},{x:-50,y:38,rx:8,ry:5},
  {x:95,y:70,rx:8,ry:5},{x:178,y:28,rx:10,ry:7},{x:132,y:85,rx:8,ry:6},
  {x:-10,y:80,rx:9,ry:6},{x:200,y:10,rx:8,ry:5},{x:35,y:65,rx:6,ry:4},{x:160,y:90,rx:8,ry:6}
];
[...FORESTS_NAMED,...FORESTS_SMALL].forEach(f=>{
  html+=`<ellipse cx="${f.x}" cy="${f.y}" rx="${f.rx}" ry="${f.ry}" fill="#1a3018" opacity="0.9"/>`;
  html+=`<ellipse cx="${f.x}" cy="${f.y}" rx="${f.rx}" ry="${f.ry}" fill="url(#tree-pat)" opacity="0.7"/>`;
});
FORESTS_NAMED.forEach(f=>{
  html+=`<text x="${f.x}" y="${f.y+f.ry+2.2}" fill="rgba(60,120,60,0.5)" font-size="1.4" text-anchor="middle" font-style="italic" font-family="serif">${f.name}</text>`;
});

// 12. Lakes
const LAKES=[
  {x:48,y:-6,rx:4,ry:2.5,name:'Highland Loch'},
  {x:158,y:72,rx:5,ry:3,name:'Ember Reservoir'}
];
LAKES.forEach(l=>{
  html+=`<ellipse cx="${l.x}" cy="${l.y}" rx="${l.rx}" ry="${l.ry}" fill="#162a40" stroke="#2a6090" stroke-width="0.3"/>`;
  html+=`<text x="${l.x}" y="${l.y+l.ry+1.8}" fill="rgba(42,96,144,0.5)" font-size="1.2" text-anchor="middle" font-style="italic" font-family="serif">${l.name}</text>`;
});

// 13. Marshland
html+=`<ellipse cx="-55" cy="48" rx="8" ry="5" fill="url(#marsh-pat)" opacity="0.8"/>`;
html+=`<ellipse cx="-52" cy="52" rx="6" ry="4" fill="url(#marsh-pat)" opacity="0.6"/>`;
html+=`<ellipse cx="60" cy="108" rx="10" ry="6" fill="url(#marsh-pat)" opacity="0.7"/>`;
html+=`<text x="-53" y="58" fill="rgba(40,100,140,0.4)" font-size="1" text-anchor="middle" font-style="italic" font-family="serif">Western Delta</text>`;
html+=`<text x="60" y="116" fill="rgba(40,100,140,0.4)" font-size="1" text-anchor="middle" font-style="italic" font-family="serif">Southern Wetland</text>`;

// 14. Agricultural zones
const AGRI=[
  {x:10,y:35,w:6,h:4},{x:-30,y:28,w:5,h:3.5},{x:25,y:42,w:5,h:4},{x:-15,y:50,w:6,h:3},
  {x:100,y:32,w:6,h:4},{x:130,y:48,w:5,h:3.5},{x:148,y:38,w:6,h:4},{x:170,y:62,w:5,h:3},{x:115,y:65,w:5,h:3.5}
];
AGRI.forEach(a=>{
  html+=`<rect x="${a.x-a.w/2}" y="${a.y-a.h/2}" width="${a.w}" height="${a.h}" fill="#2a3a24" opacity="0.5" rx="0.5"/>`;
});

// 15. Moorland
const MOOR=[
  {x:42,y:-25,rx:8,ry:6},{x:80,y:-10,rx:7,ry:5},{x:45,y:70,rx:9,ry:6},{x:78,y:95,rx:8,ry:5}
];
MOOR.forEach(m=>{
  html+=`<ellipse cx="${m.x}" cy="${m.y}" rx="${m.rx}" ry="${m.ry}" fill="rgba(60,40,30,0.08)"/>`;
});

html+=`</g>`; // close land-clip group

// 16. Rivers
const RIVERS=[
  {name:'Silvervein River',pts:[[58,-5],[50,4],[38,12],[22,22],[5,32],[-18,42],[-45,50],[-74,56]],w0:0.8,w1:1.4},
  {name:'Ember River',pts:[[67,36],[82,38],[102,41],[130,46],[165,52],[200,58],[240,64],[260,68]],w0:0.8,w1:1.6},
  {name:'Southflow River',pts:[[62,62],[64,70],[68,80],[70,90],[66,100],[60,112],[55,120]],w0:0.6,w1:1.0},
  {name:'Frost Creek',pts:[[56,-12],[48,-20],[38,-28],[25,-38],[12,-48]],w0:0.5,w1:0.8}
];
function bezierPath(pts){
  if(pts.length<2) return '';
  let d=`M${pts[0][0]},${pts[0][1]}`;
  if(pts.length===2){
    d+=` L${pts[1][0]},${pts[1][1]}`;
    return d;
  }
  for(let i=0;i<pts.length-1;i++){
    const p0=pts[Math.max(0,i-1)],p1=pts[i],p2=pts[i+1],p3=pts[Math.min(pts.length-1,i+2)];
    const cp1x=p1[0]+(p2[0]-p0[0])/6,cp1y=p1[1]+(p2[1]-p0[1])/6;
    const cp2x=p2[0]-(p3[0]-p1[0])/6,cp2y=p2[1]-(p3[1]-p1[1])/6;
    d+=` C${cp1x.toFixed(1)},${cp1y.toFixed(1)} ${cp2x.toFixed(1)},${cp2y.toFixed(1)} ${p2[0]},${p2[1]}`;
  }
  return d;
}
RIVERS.forEach(r=>{
  const d=bezierPath(r.pts);
  html+=`<path d="${d}" fill="none" stroke="rgba(42,96,144,0.15)" stroke-width="${r.w1*3}" stroke-linecap="round"/>`;
  html+=`<path d="${d}" fill="none" stroke="#2a6090" stroke-width="${r.w1}" stroke-linecap="round"/>`;
  html+=`<path d="${d}" fill="none" stroke="rgba(80,160,220,0.2)" stroke-width="${r.w0*0.4}" stroke-linecap="round"/>`;
});
// River labels
RIVERS.forEach(r=>{
  const mid=Math.floor(r.pts.length/2);
  const mp=r.pts[mid];
  let angle=0;
  if(mid>0){
    const prev=r.pts[mid-1];
    angle=Math.atan2(mp[1]-prev[1],mp[0]-prev[0])*180/Math.PI;
    if(angle>90) angle-=180; if(angle<-90) angle+=180;
  }
  html+=`<text x="${mp[0]}" y="${mp[1]-1.5}" fill="rgba(42,96,144,0.4)" font-size="1.3" text-anchor="middle" font-style="italic" font-family="serif" transform="rotate(${angle.toFixed(1)},${mp[0]},${mp[1]-1.5})">${r.name}</text>`;
});
// Tributaries
const TRIBS=[
  [[42,8],[30,2],[18,-4]],[[48,14],[40,20]],
  [[78,40],[88,48],[95,56]],[[110,44],[118,52]],
  [[65,74],[72,68]],[[58,90],[48,88]]
];
TRIBS.forEach(pts=>{
  const d=bezierPath(pts);
  html+=`<path d="${d}" fill="none" stroke="rgba(42,96,144,0.4)" stroke-width="0.2" stroke-linecap="round"/>`;
});

// 17. Small islands
const ISLANDS=[[-85,30,4,2.5],[-82,50,3,2],[270,40,5,3],[265,20,3,2],[250,100,4,2.5],[-60,110,3,2]];
ISLANDS.forEach(isl=>{
  html+=`<ellipse cx="${isl[0]}" cy="${isl[1]}" rx="${isl[2]}" ry="${isl[3]}" fill="#243828" stroke="rgba(100,140,180,0.15)" stroke-width="0.3"/>`;
});

// Grid reference labels
const gridLetters='ABCDEFGHIJKLMNOPQRS';
let gi=0;
for(let gx=-80;gx<=260;gx+=20){
  if(gi<gridLetters.length){
    html+=`<text x="${gx}" y="${FULL.y+2.5}" fill="rgba(100,140,180,0.15)" font-size="1.5" text-anchor="middle" font-family="sans-serif">${gridLetters[gi]}</text>`;
    gi++;
  }
}
let gn=1;
for(let gy=-40;gy<=120;gy+=20){
  html+=`<text x="${FULL.x+3}" y="${gy+0.5}" fill="rgba(100,140,180,0.15)" font-size="1.5" text-anchor="start" font-family="sans-serif">${gn}</text>`;
  gn++;
}

// 19. Scale bar
html+=`<g transform="translate(200,110)">`;
html+=`<rect x="0" y="0" width="10" height="1" fill="#e8e4d8" opacity="0.5"/>`;
html+=`<rect x="10" y="0" width="10" height="1" fill="#0d1a2a" stroke="#e8e4d8" stroke-width="0.15" opacity="0.5"/>`;
html+=`<rect x="20" y="0" width="10" height="1" fill="#e8e4d8" opacity="0.5"/>`;
html+=`<line x1="0" y1="-0.3" x2="0" y2="1.3" stroke="#e8e4d8" stroke-width="0.15" opacity="0.5"/>`;
html+=`<line x1="10" y1="-0.3" x2="10" y2="1.3" stroke="#e8e4d8" stroke-width="0.15" opacity="0.5"/>`;
html+=`<line x1="20" y1="-0.3" x2="20" y2="1.3" stroke="#e8e4d8" stroke-width="0.15" opacity="0.5"/>`;
html+=`<line x1="30" y1="-0.3" x2="30" y2="1.3" stroke="#e8e4d8" stroke-width="0.15" opacity="0.5"/>`;
html+=`<text x="0" y="3" fill="#e8e4d8" font-size="1.2" text-anchor="middle" opacity="0.5" font-family="sans-serif">0</text>`;
html+=`<text x="10" y="3" fill="#e8e4d8" font-size="1.2" text-anchor="middle" opacity="0.5" font-family="sans-serif">10km</text>`;
html+=`<text x="20" y="3" fill="#e8e4d8" font-size="1.2" text-anchor="middle" opacity="0.5" font-family="sans-serif">20km</text>`;
html+=`<text x="30" y="3" fill="#e8e4d8" font-size="1.2" text-anchor="middle" opacity="0.5" font-family="sans-serif">30km</text>`;
html+=`</g>`;

// 20. Compass rose
html+=`<g transform="translate(220,-40)">`;
html+=`<circle cx="0" cy="0" r="8" fill="rgba(13,26,42,0.6)" stroke="rgba(200,195,180,0.15)" stroke-width="0.3"/>`;
html+=`<line x1="0" y1="-7" x2="0" y2="7" stroke="rgba(200,195,180,0.12)" stroke-width="0.2"/>`;
html+=`<line x1="-7" y1="0" x2="7" y2="0" stroke="rgba(200,195,180,0.12)" stroke-width="0.2"/>`;
html+=`<line x1="-4.5" y1="-4.5" x2="4.5" y2="4.5" stroke="rgba(200,195,180,0.06)" stroke-width="0.15"/>`;
html+=`<line x1="4.5" y1="-4.5" x2="-4.5" y2="4.5" stroke="rgba(200,195,180,0.06)" stroke-width="0.15"/>`;
html+=`<polygon points="0,-6.5 -1.2,-1.5 1.2,-1.5" fill="rgba(200,60,60,0.6)"/>`;
html+=`<polygon points="0,6.5 -1.2,1.5 1.2,1.5" fill="rgba(200,195,180,0.25)"/>`;
html+=`<polygon points="-6.5,0 -1.5,1.2 -1.5,-1.2" fill="rgba(200,195,180,0.2)"/>`;
html+=`<polygon points="6.5,0 1.5,1.2 1.5,-1.2" fill="rgba(200,195,180,0.2)"/>`;
html+=`<text x="0" y="-3.8" fill="rgba(255,255,255,0.5)" font-size="2" text-anchor="middle" font-weight="bold" font-family="sans-serif">N</text>`;
html+=`<text x="0" y="5.8" fill="rgba(200,195,180,0.3)" font-size="1.5" text-anchor="middle" font-family="sans-serif">S</text>`;
html+=`<text x="5" y="0.8" fill="rgba(200,195,180,0.3)" font-size="1.5" text-anchor="middle" font-family="sans-serif">E</text>`;
html+=`<text x="-5" y="0.8" fill="rgba(200,195,180,0.3)" font-size="1.5" text-anchor="middle" font-family="sans-serif">W</text>`;
html+=`</g>`;

// 21. Choke point zones
const PASSES=[
  {name:'Northgate Pass',x:62,y:12,w:24,h:8},
  {name:"The King's Corridor",x:62,y:36,w:28,h:10},
  {name:'Southmaw Gap',x:62,y:59,w:24,h:8}
];
PASSES.forEach(p=>{
  html+=`<rect x="${p.x-p.w/2}" y="${p.y-p.h/2}" width="${p.w}" height="${p.h}" fill="none" stroke="rgba(200,160,60,0.2)" stroke-width="0.3" stroke-dasharray="1.5,1" rx="1"/>`;
  html+=`<text x="${p.x}" y="${p.y-p.h/2-1.2}" fill="rgba(200,160,60,0.45)" font-size="1.6" text-anchor="middle" font-weight="bold" font-family="sans-serif">${p.name}</text>`;
});

// 23. Urban areas
towns.forEach(t=>{
  html+=`<ellipse cx="${t.x}" cy="${t.y}" rx="3" ry="2.5" fill="rgba(150,150,170,0.04)"/>`;
});

// 22. Road connections (solid lines with casing)
distances.forEach(d=>{
  const t1=townMap[d.from],t2=townMap[d.to];
  if(!t1||!t2) return;
  html+=`<line x1="${t1.x}" y1="${t1.y}" x2="${t2.x}" y2="${t2.y}" stroke="rgba(60,45,15,0.25)" stroke-width="0.9" stroke-linecap="round"/>`;
  html+=`<line class="conn conn-${d.from} conn-${d.to}" x1="${t1.x}" y1="${t1.y}" x2="${t2.x}" y2="${t2.y}" stroke="#807848" stroke-width="0.35" stroke-linecap="round"/>`;
});
// A-road overlay for customs connections
distances.forEach(d=>{
  const t1=townMap[d.from],t2=townMap[d.to];
  if(!t1||!t2) return;
  if(t1.name.includes('Customs')||t2.name.includes('Customs')){
    html+=`<line x1="${t1.x}" y1="${t1.y}" x2="${t2.x}" y2="${t2.y}" stroke="#b8883a" stroke-width="0.6" stroke-linecap="round" style="pointer-events:none;"/>`;
  }
});

// Distance labels
distances.forEach(d=>{
  const t1=townMap[d.from],t2=townMap[d.to];
  if(!t1||!t2) return;
  const mx=(t1.x+t2.x)/2,my=(t1.y+t2.y)/2;
  let angle=Math.atan2(t2.y-t1.y,t2.x-t1.x)*180/Math.PI;
  if(angle>90) angle-=180; if(angle<-90) angle+=180;
  html+=`<text class="dist-label dlbl-${d.from} dlbl-${d.to}" x="${mx}" y="${my}" fill="rgba(232,228,216,0.22)" font-size="1.3" text-anchor="middle" transform="rotate(${angle.toFixed(1)},${mx.toFixed(1)},${my.toFixed(1)})" dy="-0.5" style="pointer-events:none;">${d.km}km</text>`;
});

// 26. Territory labels (very large, very subtle)
html+=`<text x="20" y="35" fill="rgba(68,136,255,0.06)" font-size="8" text-anchor="middle" font-weight="bold" font-family="sans-serif">BLUE TERRITORY</text>`;
html+=`<text x="130" y="35" fill="rgba(255,85,85,0.06)" font-size="8" text-anchor="middle" font-weight="bold" font-family="sans-serif">RED TERRITORY</text>`;

// 24. Town markers
towns.forEach(t=>{
  const isCustoms=t.name.includes('Customs');
  const color=t.side==='blue'?'#4488ff':'#ff5555';
  const glowId=t.side==='blue'?'glow-blue':'glow-red';
  html+=`<circle cx="${t.x}" cy="${t.y}" r="${isCustoms?4.5:3.5}" fill="url(#${glowId})" style="pointer-events:none;"/>`;
  html+=`<a href="town_view.php?id=${t.id}" class="town-link" data-id="${t.id}">`;
  if(isCustoms){
    html+=`<rect x="${t.x-2}" y="${t.y-2}" width="4" height="4" fill="${color}" stroke="rgba(255,255,255,0.9)" stroke-width="0.25" rx="0.5" class="town-marker" style="cursor:pointer;"/>`;
  } else {
    html+=`<circle cx="${t.x}" cy="${t.y}" r="1.5" fill="${color}" stroke="rgba(255,255,255,0.9)" stroke-width="0.25" class="town-marker" style="cursor:pointer;"/>`;
  }
  html+=`</a>`;
  // 25. Town labels
  const yOff=isCustoms?-3.8:-3;
  html+=`<text x="${t.x}" y="${t.y+yOff}" fill="#e8e4d8" font-size="${isCustoms?2.2:1.8}" text-anchor="middle" font-weight="bold" style="pointer-events:none;paint-order:stroke;stroke:rgba(0,0,0,0.8);stroke-width:0.5px;">${t.name}</text>`;
});

// Inject all generated SVG
const container=document.createElementNS(NS,'g');
container.innerHTML=html;
svg.appendChild(container);

/* ── Tooltip & hover ── */
document.querySelectorAll('.town-link').forEach(link=>{
  link.addEventListener('mouseenter',function(){
    const id=this.dataset.id, town=townMap[id];
    document.querySelectorAll(`.conn-${id}`).forEach(el=>{
      el.setAttribute('stroke','#b8883a');
      el.setAttribute('stroke-width','0.6');
    });
    document.querySelectorAll(`.dlbl-${id}`).forEach(el=>{
      el.setAttribute('fill','rgba(232,228,216,0.9)');
      el.setAttribute('font-size','1.8');
      el.setAttribute('font-weight','bold');
    });
    let resHtml='';
    if(town.resources && town.resources.length>0){
      resHtml='<div style="margin-top:8px;display:grid;grid-template-columns:1fr auto;gap:2px 14px;">';
      town.resources.forEach(r=>{
        resHtml+=`<span style="color:#aab;">${r.name}</span><span style="text-align:right;color:#7ee;font-weight:600;">${r.stock}</span>`;
      });
      resHtml+='</div>';
    } else if(town.population===0){
      resHtml='<div style="margin-top:8px;color:#776;">Intel unavailable</div>';
    } else {
      resHtml='<div style="margin-top:8px;color:#888;">Global Market Access</div>';
    }
    tooltip.innerHTML=`
      <div style="font-size:14px;font-weight:bold;color:${town.side==='blue'?'#6af':'#f66'};margin-bottom:4px;">${town.name}</div>
      ${town.population>0?`<div>Population: <strong>${town.population.toLocaleString()}</strong></div>`:'<div style="color:#776;">Population: <em>Intel unavailable</em></div>'}
      ${resHtml}
    `;
    tooltip.style.display='block';
  });
  link.addEventListener('mousemove',function(e){
    const tt=tooltip, rect=tt.getBoundingClientRect();
    let lx=e.clientX+15, ly=e.clientY+15;
    if(lx+300>window.innerWidth) lx=e.clientX-rect.width-15;
    if(ly+rect.height>window.innerHeight) ly=e.clientY-rect.height-15;
    tt.style.left=lx+'px'; tt.style.top=ly+'px';
  });
  link.addEventListener('mouseleave',function(){
    const id=this.dataset.id,town=townMap[id];
    document.querySelectorAll(`.conn-${id}`).forEach(el=>{
      el.setAttribute('stroke','#807848');
      el.setAttribute('stroke-width','0.35');
    });
    document.querySelectorAll(`.dlbl-${id}`).forEach(el=>{
      el.setAttribute('fill','rgba(232,228,216,0.22)');
      el.setAttribute('font-size','1.3');
      el.removeAttribute('font-weight');
    });
    tooltip.style.display='none';
  });
});
})();
</script>
<?php
include "files/scripts.php";
?>
