<?php require __DIR__ . '/map_hex.php'; exit; // Hex grid map v2
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
#map-wrapper{position:relative;height:75vh;min-height:500px;overflow:hidden;cursor:grab;background:#aad3df;border-radius:6px;}
#map-wrapper.dragging{cursor:grabbing;}
#map-svg{width:100%;height:100%;display:block;}
#map-tooltip{display:none;position:fixed;pointer-events:none;z-index:9999;background:rgba(255,255,255,0.96);border:1px solid #ddd;border-radius:6px;padding:12px 16px;color:#333;font-size:12px;max-width:300px;box-shadow:0 4px 12px rgba(0,0,0,0.1);}
#map-controls{position:absolute;top:12px;right:12px;display:flex;flex-direction:column;gap:4px;z-index:100;}
#map-controls button{width:36px;height:36px;border:1px solid #ccc;border-radius:4px;background:#fff;color:#333;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s;}
#map-controls button:hover{background:#f0f0f0;}
#map-legend{position:absolute;bottom:12px;left:12px;background:rgba(255,255,255,0.95);border:1px solid #ddd;border-radius:4px;padding:10px 14px;z-index:100;font-size:11px;color:#333;}
#map-legend h6{margin:0 0 6px;font-size:10px;color:#666;text-transform:uppercase;letter-spacing:1px;}
.legend-item{display:flex;align-items:center;gap:8px;margin:3px 0;}
.legend-swatch{flex-shrink:0;}
a.town-link:hover .town-marker{filter:brightness(1.05) drop-shadow(0 0 1px rgba(0,0,0,0.3));}
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
          <div class="legend-item"><svg class="legend-swatch" width="20" height="8" xmlns="http://www.w3.org/2000/svg"><line x1="0" y1="4" x2="20" y2="4" stroke="#a05020" stroke-width="5" stroke-linecap="round"/><line x1="0" y1="4" x2="20" y2="4" stroke="#e87d28" stroke-width="3" stroke-linecap="round"/></svg>A-road</div>
          <div class="legend-item"><svg class="legend-swatch" width="20" height="8" xmlns="http://www.w3.org/2000/svg"><line x1="0" y1="4" x2="20" y2="4" stroke="#8a6820" stroke-width="4" stroke-linecap="round"/><line x1="0" y1="4" x2="20" y2="4" stroke="#f0c848" stroke-width="2.5" stroke-linecap="round"/></svg>B-road</div>
          <div class="legend-item"><svg class="legend-swatch" width="20" height="8" xmlns="http://www.w3.org/2000/svg"><line x1="0" y1="4" x2="20" y2="4" stroke="#c0c0c0" stroke-width="3" stroke-linecap="round"/><line x1="0" y1="4" x2="20" y2="4" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>Minor road</div>
          <div class="legend-item"><span class="legend-swatch" style="display:inline-block;width:14px;height:14px;background:#c8d8a0;border-radius:2px;"></span>Woodland</div>
          <div class="legend-item"><span class="legend-swatch" style="display:inline-block;width:14px;height:14px;background:#aad3df;border:1px solid #8eb4c0;border-radius:2px;"></span>Water</div>
          <div class="legend-item"><svg class="legend-swatch" width="20" height="8" xmlns="http://www.w3.org/2000/svg"><line x1="0" y1="4" x2="20" y2="4" stroke="#d4b08c" stroke-width="1" opacity="0.5"/></svg>Contour</div>
        </div>
        <svg id="map-svg" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
          <defs>
            <pattern id="tree-pat" width="2.5" height="2.5" patternUnits="userSpaceOnUse">
              <circle cx="0.8" cy="0.8" r="0.45" fill="rgba(64,128,48,0.3)"/>
              <circle cx="2.0" cy="1.8" r="0.4" fill="rgba(64,128,48,0.25)"/>
            </pattern>
            <pattern id="marsh-pat" width="4" height="2.5" patternUnits="userSpaceOnUse">
              <line x1="0" y1="0.8" x2="1.8" y2="0.8" stroke="#8eb4c0" stroke-width="0.2" opacity="0.3"/>
              <line x1="2.2" y1="1.8" x2="4" y2="1.8" stroke="#8eb4c0" stroke-width="0.2" opacity="0.25"/>
            </pattern>
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
let html='';

function bezierPath(pts){
  if(pts.length<2) return '';
  let d=`M${pts[0][0]},${pts[0][1]}`;
  if(pts.length===2){ d+=` L${pts[1][0]},${pts[1][1]}`; return d; }
  for(let i=0;i<pts.length-1;i++){
    const p0=pts[Math.max(0,i-1)],p1=pts[i],p2=pts[i+1],p3=pts[Math.min(pts.length-1,i+2)];
    const cp1x=p1[0]+(p2[0]-p0[0])/6,cp1y=p1[1]+(p2[1]-p0[1])/6;
    const cp2x=p2[0]-(p3[0]-p1[0])/6,cp2y=p2[1]-(p3[1]-p1[1])/6;
    d+=` C${cp1x.toFixed(1)},${cp1y.toFixed(1)} ${cp2x.toFixed(1)},${cp2y.toFixed(1)} ${p2[0]},${p2[1]}`;
  }
  return d;
}
function irregularBlob(cx,cy,rx,ry,seed){
  const n=8,pts=[];
  for(let i=0;i<n;i++){
    const a=(i/n)*Math.PI*2,v=0.75+0.25*Math.sin(seed*7.3+i*2.7);
    pts.push([cx+Math.cos(a)*rx*v,cy+Math.sin(a)*ry*v]);
  }
  let d='M'+pts[0][0].toFixed(1)+','+pts[0][1].toFixed(1);
  for(let i=0;i<n;i++){
    const p0=pts[(i-1+n)%n],p1=pts[i],p2=pts[(i+1)%n],p3=pts[(i+2)%n];
    const c1x=p1[0]+(p2[0]-p0[0])/6,c1y=p1[1]+(p2[1]-p0[1])/6;
    const c2x=p2[0]-(p3[0]-p1[0])/6,c2y=p2[1]-(p3[1]-p1[1])/6;
    d+=' C'+c1x.toFixed(1)+','+c1y.toFixed(1)+' '+c2x.toFixed(1)+','+c2y.toFixed(1)+' '+p2[0].toFixed(1)+','+p2[1].toFixed(1);
  }
  return d+'Z';
}

// 1. Sea base
html+=`<rect x="${FULL.x}" y="${FULL.y}" width="${FULL.w}" height="${FULL.h}" fill="#aad3df"/>`;

// 2. Landmass with detailed coastline
const COAST='M-72,-38 C-58,-46 -35,-44 -15,-43 C5,-42 25,-41 42,-42 C52,-43 58,-46 62,-47 C66,-46 72,-43 85,-42 C105,-41 125,-44 148,-42 C168,-40 190,-42 210,-38 C228,-35 242,-33 254,-28 C262,-20 266,-10 266,0 C266,8 266,14 265,18 C264,20 262,22 261,23 L259,25 L261,27 C263,29 265,32 266,36 C267,48 267,60 264,74 C260,86 253,97 243,104 C233,110 223,113 210,114 C192,116 175,115 158,114 C140,113 122,116 105,118 C88,119 72,115 55,117 C38,119 20,118 5,115 C-10,112 -25,108 -38,102 C-50,96 -58,90 -64,82 C-70,72 -74,62 -76,52 C-78,44 -78,36 -77,30 L-76,28 L-78,26 C-80,24 -80,22 -80,20 C-79,14 -78,4 -76,-6 C-74,-18 -73,-30 -72,-38Z';
html+=`<path d="${COAST}" fill="#f0ede6" stroke="#8eb4c0" stroke-width="0.3"/>`;

// 3. Land clip path
html+=`<clipPath id="land-clip"><path d="${COAST}"/></clipPath>`;
html+=`<g clip-path="url(#land-clip)">`;

// 4. Grid (very faint)
for(let gx=-80;gx<=260;gx+=20){
  html+=`<line x1="${gx}" y1="${FULL.y}" x2="${gx}" y2="${FULL.y+FULL.h}" stroke="#d0d0d0" stroke-width="0.1" opacity="0.15"/>`;
}
for(let gy=-40;gy<=120;gy+=20){
  html+=`<line x1="${FULL.x}" y1="${gy}" x2="${FULL.x+FULL.w}" y2="${gy}" stroke="#d0d0d0" stroke-width="0.1" opacity="0.15"/>`;
}

// 5. Moorland/heath patches
const MOORS=[
  {x:-40,y:50,rx:12,ry:8,name:'Westmoor Heath'},{x:180,y:40,rx:14,ry:9,name:'Eastmarch Downs'},
  {x:62,y:-20,rx:10,ry:7,name:'High Moor'},{x:-10,y:75,rx:10,ry:6,name:'Bracken Moor'},
  {x:200,y:70,rx:11,ry:7,name:'Thornley Moor'},{x:45,y:90,rx:9,ry:6,name:null},
  {x:78,y:-30,rx:8,ry:5,name:null},{x:-55,y:80,rx:8,ry:5,name:null},{x:240,y:50,rx:9,ry:6,name:null}
];
MOORS.forEach(m=>{
  html+=`<ellipse cx="${m.x}" cy="${m.y}" rx="${m.rx}" ry="${m.ry}" fill="#e8e0d0" opacity="0.5"/>`;
  if(m.name) html+=`<text x="${m.x}" y="${m.y+m.ry+2}" fill="#8a7050" font-size="1.1" text-anchor="middle" font-style="italic" font-family="serif">${m.name}</text>`;
});

// 6. Agricultural land (very subtle)
const AGRI=[
  {x:10,y:35,w:6,h:4},{x:-30,y:28,w:5,h:3.5},{x:25,y:42,w:5,h:4},{x:-15,y:50,w:6,h:3},
  {x:100,y:32,w:6,h:4},{x:130,y:48,w:5,h:3.5},{x:148,y:38,w:6,h:4},{x:170,y:62,w:5,h:3},
  {x:115,y:65,w:5,h:3.5},{x:-50,y:25,w:4,h:3},{x:210,y:30,w:5,h:3},{x:-20,y:45,w:4,h:3}
];
AGRI.forEach(a=>{
  html+=`<rect x="${a.x-a.w/2}" y="${a.y-a.h/2}" width="${a.w}" height="${a.h}" fill="#eaeddf" rx="0.5" opacity="0.4"/>`;
});

// 7. Forests (irregular shapes)
const FORESTS=[
  {x:-20,y:20,rx:18,ry:12,name:'Darkwood',seed:1.2},
  {x:22,y:8,rx:10,ry:7,name:'Pine Ridge',seed:2.5},
  {x:120,y:14,rx:14,ry:9,name:'Ashwood',seed:3.8},
  {x:155,y:55,rx:16,ry:11,name:'Red Oak Forest',seed:4.1},
  {x:-50,y:15,rx:8,ry:6,name:'Bramble Wood',seed:5.3},
  {x:-30,y:60,rx:7,ry:5,name:'Thorn Copse',seed:6.7},
  {x:150,y:20,rx:9,ry:6,name:'Oakley Wood',seed:7.2},
  {x:200,y:45,rx:10,ry:7,name:'Hazel Plantation',seed:8.4},
  {x:-20,y:35,rx:7,ry:5,name:'Birch Grove',seed:9.1},
  {x:170,y:75,rx:9,ry:7,name:'Wildwood',seed:10.6},
  {x:100,y:5,rx:7,ry:5,name:'Elder Spinney',seed:11.3},
  {x:230,y:30,rx:8,ry:6,name:'Yew Wood',seed:12.8}
];
const FORESTS_SMALL=[
  {x:8,y:48,rx:8,ry:5,seed:20},{x:-35,y:55,rx:6,ry:4,seed:21},{x:-60,y:35,rx:5,ry:4,seed:22},
  {x:95,y:70,rx:6,ry:4,seed:23},{x:178,y:28,rx:7,ry:5,seed:24},{x:132,y:85,rx:6,ry:5,seed:25},
  {x:-10,y:80,rx:7,ry:5,seed:26},{x:210,y:15,rx:6,ry:4,seed:27},{x:35,y:65,rx:5,ry:3,seed:28},
  {x:160,y:90,rx:6,ry:5,seed:29},{x:-45,y:70,rx:4,ry:3,seed:30},{x:240,y:60,rx:5,ry:4,seed:31},
  {x:-65,y:45,rx:4,ry:3,seed:32},{x:185,y:55,rx:5,ry:3,seed:33},{x:250,y:20,rx:4,ry:3,seed:34},
  {x:-55,y:10,rx:4,ry:3,seed:35},{x:140,y:40,rx:5,ry:3,seed:36},{x:220,y:55,rx:4,ry:3,seed:37}
];
[...FORESTS,...FORESTS_SMALL].forEach(f=>{
  const d=irregularBlob(f.x,f.y,f.rx,f.ry,f.seed||1);
  html+=`<path d="${d}" fill="#c8d8a0"/>`;
  html+=`<path d="${d}" fill="url(#tree-pat)"/>`;
});
FORESTS.forEach(f=>{
  html+=`<text x="${f.x}" y="${f.y+f.ry+2}" fill="#5a7a3a" font-size="1.2" text-anchor="middle" font-style="italic" font-family="serif">${f.name}</text>`;
});

// 8. Beach/dune areas
html+=`<path d="M-80,18 C-78,16 -76,15 -73,17 C-70,19 -72,22 -76,22 C-79,22 -81,20 -80,18Z" fill="#e8dcc0" opacity="0.6"/>`;
html+=`<path d="M-77,28 C-74,28 -72,30 -74,32 C-76,34 -79,33 -79,31 C-79,29 -78,28 -77,28Z" fill="#e8dcc0" opacity="0.6"/>`;
html+=`<path d="M261,16 C264,16 266,18 265,20 C264,22 261,22 260,20 C259,18 260,16 261,16Z" fill="#e8dcc0" opacity="0.6"/>`;
html+=`<path d="M262,30 C265,30 267,32 266,34 C265,36 262,36 261,34 C260,32 261,30 262,30Z" fill="#e8dcc0" opacity="0.6"/>`;
html+=`<path d="M-70,-38 C-60,-40 -50,-42 -40,-42 C-30,-41 -20,-40 -10,-41 L-10,-39 C-20,-38 -30,-39 -40,-40 C-50,-40 -60,-38 -70,-36Z" fill="#e8dcc0" opacity="0.3"/>`;
html+=`<path d="M200,-38 C210,-36 220,-35 230,-34 C240,-33 248,-32 254,-28 L252,-26 C246,-30 238,-31 228,-32 C218,-33 208,-36 198,-37Z" fill="#e8dcc0" opacity="0.3"/>`;

// 9. Contour lines (very subtle, fewer)
const mtSegments=[
  {cx:62,cy:-16,rxBase:22,ryBase:28},{cx:62,cy:23,rxBase:12,ryBase:7},
  {cx:62,cy:49,rxBase:12,ryBase:7},{cx:62,cy:87,rxBase:22,ryBase:26}
];
mtSegments.forEach(seg=>{
  [0.9,0.65,0.4,0.2].forEach(frac=>{
    const rx=seg.rxBase*frac,ry=seg.ryBase*frac;
    if(rx<1.5||ry<1) return;
    html+=`<ellipse cx="${seg.cx}" cy="${seg.cy}" rx="${rx.toFixed(1)}" ry="${ry.toFixed(1)}" fill="none" stroke="#d4b08c" stroke-width="0.12" opacity="0.3"/>`;
  });
});
// Spot heights
const spotHeights=[
  {x:62,y:-18,label:'1247'},{x:62,y:23,label:'856'},
  {x:62,y:49,label:'891'},{x:62,y:85,label:'1102'}
];
spotHeights.forEach(s=>{
  html+=`<text x="${s.x+1.5}" y="${s.y+0.5}" fill="#8a7050" font-size="1" font-family="sans-serif" opacity="0.7">&#9650; ${s.label}</text>`;
});

// 10. Lakes
const LAKES=[
  {x:48,y:-6,rx:4,ry:2.5,name:'Highland Loch'},{x:158,y:72,rx:5,ry:3,name:'Ember Reservoir'},
  {x:-35,y:30,rx:2,ry:1.5,name:'Mill Pond'},{x:190,y:60,rx:3,ry:2,name:'Beacon Tarn'}
];
LAKES.forEach(l=>{
  html+=`<ellipse cx="${l.x}" cy="${l.y}" rx="${l.rx}" ry="${l.ry}" fill="#aad3df" stroke="#8eb4c0" stroke-width="0.2"/>`;
  html+=`<text x="${l.x}" y="${l.y+l.ry+1.6}" fill="#5a8a9a" font-size="1" text-anchor="middle" font-style="italic" font-family="serif">${l.name}</text>`;
});

// 11. Marshland (subtler)
html+=`<ellipse cx="-55" cy="48" rx="7" ry="4" fill="#dde8e0" opacity="0.6"/>`;
html+=`<ellipse cx="-55" cy="48" rx="7" ry="4" fill="url(#marsh-pat)" opacity="0.5"/>`;
html+=`<ellipse cx="-52" cy="52" rx="5" ry="3" fill="#dde8e0" opacity="0.6"/>`;
html+=`<ellipse cx="-52" cy="52" rx="5" ry="3" fill="url(#marsh-pat)" opacity="0.5"/>`;
html+=`<ellipse cx="60" cy="108" rx="9" ry="5" fill="#dde8e0" opacity="0.6"/>`;
html+=`<ellipse cx="60" cy="108" rx="9" ry="5" fill="url(#marsh-pat)" opacity="0.5"/>`;
html+=`<text x="-53" y="57" fill="#5a8a9a" font-size="0.9" text-anchor="middle" font-style="italic" font-family="serif">Western Delta</text>`;
html+=`<text x="60" y="115" fill="#5a8a9a" font-size="0.9" text-anchor="middle" font-style="italic" font-family="serif">Southern Wetland</text>`;

html+=`</g>`; // close land-clip group

// 12. Rivers
const RIVERS=[
  {name:'Silvervein River',pts:[[58,-5],[50,4],[38,12],[22,22],[5,32],[-18,42],[-45,50],[-74,56]],w:1.0},
  {name:'Ember River',pts:[[67,36],[82,38],[102,41],[130,46],[165,52],[200,58],[240,64],[260,68]],w:1.2},
  {name:'Southflow River',pts:[[62,62],[64,70],[68,80],[70,90],[66,100],[60,112],[55,120]],w:0.8},
  {name:'Frost Creek',pts:[[56,-12],[48,-20],[38,-28],[25,-38],[12,-48]],w:0.6}
];
RIVERS.forEach(r=>{
  const d=bezierPath(r.pts);
  html+=`<path d="${d}" fill="none" stroke="#aad3df" stroke-width="${r.w}" stroke-linecap="round"/>`;
});
RIVERS.forEach(r=>{
  const mid=Math.floor(r.pts.length/2);
  const mp=r.pts[mid];
  let angle=0;
  if(mid>0){
    const prev=r.pts[mid-1];
    angle=Math.atan2(mp[1]-prev[1],mp[0]-prev[0])*180/Math.PI;
    if(angle>90) angle-=180; if(angle<-90) angle+=180;
  }
  html+=`<text x="${mp[0]}" y="${mp[1]-1.2}" fill="#5a8a9a" font-size="1.1" text-anchor="middle" font-style="italic" font-family="serif" transform="rotate(${angle.toFixed(1)},${mp[0]},${mp[1]-1.2})">${r.name}</text>`;
});
const TRIBS=[
  [[42,8],[30,2],[18,-4]],[[48,14],[40,20]],
  [[78,40],[88,48],[95,56]],[[110,44],[118,52]],
  [[65,74],[72,68]],[[58,90],[48,88]]
];
TRIBS.forEach(pts=>{
  html+=`<path d="${bezierPath(pts)}" fill="none" stroke="#aad3df" stroke-width="0.25" stroke-linecap="round" opacity="0.6"/>`;
});

// Islands
const ISLANDS=[[-85,30,4,2.5],[-82,50,3,2],[270,40,5,3],[265,20,3,2],[250,100,4,2.5],[-60,110,3,2]];
ISLANDS.forEach(isl=>{
  html+=`<ellipse cx="${isl[0]}" cy="${isl[1]}" rx="${isl[2]}" ry="${isl[3]}" fill="#f0ede6" stroke="#8eb4c0" stroke-width="0.3"/>`;
});

// Port harbours
html+=`<path d="M-76,22 Q-80,22 -80,25 Q-80,28 -76,28" fill="none" stroke="#888" stroke-width="0.3"/>`;
html+=`<line x1="-76" y1="21" x2="-76" y2="29" stroke="#888" stroke-width="0.35"/>`;
html+=`<line x1="-80" y1="25" x2="-76" y2="25" stroke="#888" stroke-width="0.25"/>`;
html+=`<path d="M259,22 Q263,22 263,25 Q263,28 259,28" fill="none" stroke="#888" stroke-width="0.3"/>`;
html+=`<line x1="259" y1="21" x2="259" y2="29" stroke="#888" stroke-width="0.35"/>`;
html+=`<line x1="259" y1="25" x2="263" y2="25" stroke="#888" stroke-width="0.25"/>`;

// Minor roads
const minorRoads=[
  [[-65,20],[-58,22],[-50,24],[-42,25]],
  [[-42,25],[-48,32],[-55,38],[-55,42]],
  [[-25,10],[-20,15],[-18,20]],
  [[-15,70],[-10,65],[-5,58]],
  [[145,30],[152,28],[160,25]],
  [[170,45],[180,38],[195,30],[195,25]],
  [[235,35],[242,32],[248,28]],
  [[210,50],[215,40],[218,30],[220,15]],
  [[-45,65],[-40,60],[-35,55]],
  [[200,60],[210,55],[220,50]]
];

// Roads: ALL casings first, then ALL fills
// Minor road casings
minorRoads.forEach(pts=>{
  html+=`<path d="${bezierPath(pts)}" fill="none" stroke="#c0c0c0" stroke-width="0.6" stroke-linecap="round"/>`;
});
// B-road and A-road casings
distances.forEach(d=>{
  const t1=townMap[d.from],t2=townMap[d.to];
  if(!t1||!t2) return;
  const isA=t1.name.includes('Customs')||t2.name.includes('Customs');
  html+=`<line x1="${t1.x}" y1="${t1.y}" x2="${t2.x}" y2="${t2.y}" stroke="${isA?'#a05020':'#8a6820'}" stroke-width="${isA?1.2:0.9}" stroke-linecap="round"/>`;
});
// Minor road fills
minorRoads.forEach(pts=>{
  html+=`<path d="${bezierPath(pts)}" fill="none" stroke="#ffffff" stroke-width="0.4" stroke-linecap="round"/>`;
});
// B-road and A-road fills
distances.forEach(d=>{
  const t1=townMap[d.from],t2=townMap[d.to];
  if(!t1||!t2) return;
  const isA=t1.name.includes('Customs')||t2.name.includes('Customs');
  html+=`<line class="conn conn-${d.from} conn-${d.to}" x1="${t1.x}" y1="${t1.y}" x2="${t2.x}" y2="${t2.y}" stroke="${isA?'#e87d28':'#f0c848'}" stroke-width="${isA?0.8:0.6}" stroke-linecap="round"/>`;
});

// A-road number badges
let aWestPlaced=false,aEastPlaced=false;
distances.forEach(d=>{
  const t1=townMap[d.from],t2=townMap[d.to];
  if(!t1||!t2) return;
  const isA=t1.name.includes('Customs')||t2.name.includes('Customs');
  if(!isA) return;
  const mx=(t1.x+t2.x)/2,my=(t1.y+t2.y)/2;
  if(mx<62&&!aWestPlaced){
    html+=`<rect x="${mx-2.5}" y="${my-1.5}" width="5" height="3" rx="0.8" fill="#2a7f3b"/>`;
    html+=`<text x="${mx}" y="${my+0.5}" fill="#fff" font-size="1.4" text-anchor="middle" font-family="sans-serif" font-weight="bold">A1</text>`;
    aWestPlaced=true;
  } else if(mx>=62&&!aEastPlaced){
    html+=`<rect x="${mx-2.5}" y="${my-1.5}" width="5" height="3" rx="0.8" fill="#2a7f3b"/>`;
    html+=`<text x="${mx}" y="${my+0.5}" fill="#fff" font-size="1.4" text-anchor="middle" font-family="sans-serif" font-weight="bold">A2</text>`;
    aEastPlaced=true;
  }
});

// Distance labels
distances.forEach(d=>{
  const t1=townMap[d.from],t2=townMap[d.to];
  if(!t1||!t2) return;
  const mx=(t1.x+t2.x)/2,my=(t1.y+t2.y)/2;
  let angle=Math.atan2(t2.y-t1.y,t2.x-t1.x)*180/Math.PI;
  if(angle>90) angle-=180; if(angle<-90) angle+=180;
  html+=`<text class="dist-label dlbl-${d.from} dlbl-${d.to}" x="${mx}" y="${my}" fill="rgba(0,0,0,0.25)" font-size="1.2" text-anchor="middle" transform="rotate(${angle.toFixed(1)},${mx.toFixed(1)},${my.toFixed(1)})" dy="-0.5" style="pointer-events:none;" font-family="sans-serif">${d.km}km</text>`;
});

// Small settlements/hamlets
const hamlets=[
  {x:-55,y:42,n:'Marsh End'},{x:-65,y:20,n:'Westcove'},{x:-40,y:35,n:'Millbridge'},
  {x:-25,y:10,n:'Beacon Hill'},{x:-45,y:65,n:'Stone Cross'},{x:-15,y:70,n:'Littlemoor'},
  {x:145,y:30,n:'Easthollow'},{x:170,y:45,n:'Drybridge'},{x:195,y:25,n:'Longfield'},
  {x:210,y:50,n:'High Cross'},{x:235,y:35,n:'Saltmarsh'},{x:220,y:15,n:'Dunmore'},
  {x:55,y:-5,n:'Summit Lodge'},{x:68,y:25,n:'Iron Gate'}
];
hamlets.forEach(h=>{
  html+=`<text x="${h.x}" y="${h.y}" fill="#666" font-size="1.3" text-anchor="middle" font-style="italic" font-family="serif">${h.n}</text>`;
});

// Named features
const features=[
  {x:-30,y:8,l:'Beacon Hill &#9650;152',s:0.9},{x:185,y:85,l:'Round Hill &#9650;98',s:0.9},
  {x:55,y:-35,l:'Cairn Top &#9650;204',s:0.9},{x:-60,y:55,l:'Long Barrow',s:0.9},
  {x:160,y:35,l:'Standing Stones',s:0.9},{x:-5,y:55,l:'Hill Fort',s:0.9},
  {x:-50,y:28,l:'Home Farm',s:0.9},{x:225,y:40,l:'Grange Farm',s:0.9},
  {x:140,y:65,l:'Manor Farm',s:0.9},{x:-40,y:45,l:'Lower Green',s:0.8},
  {x:175,y:15,l:'Upper Field',s:0.8},{x:-70,y:38,l:'The Warren',s:0.8},
  {x:245,y:48,l:'Fox Covert',s:0.8}
];
features.forEach(f=>{
  html+=`<text x="${f.x}" y="${f.y}" fill="#666" font-size="${f.s}" text-anchor="middle" font-style="italic" font-family="serif">${f.l}</text>`;
});

// Urban area tints
towns.forEach(t=>{
  html+=`<ellipse cx="${t.x}" cy="${t.y}" rx="3" ry="2.5" fill="#e0d8d0" opacity="0.4"/>`;
});

// Town markers and labels
towns.forEach(t=>{
  const isCustoms=t.name.includes('Customs');
  html+=`<a href="town_view.php?id=${t.id}" class="town-link" data-id="${t.id}">`;
  if(isCustoms){
    html+=`<rect x="${t.x-1}" y="${t.y-1}" width="2" height="2" fill="#fff" stroke="#333" stroke-width="0.3" class="town-marker" style="cursor:pointer;"/>`;
  } else {
    html+=`<circle cx="${t.x}" cy="${t.y}" r="0.8" fill="#e0d8d0" stroke="#999" stroke-width="0.2" class="town-marker" style="cursor:pointer;"/>`;
  }
  html+=`</a>`;
  const yOff=isCustoms?-2.5:-2;
  html+=`<text x="${t.x}" y="${t.y+yOff}" fill="#333" font-size="${isCustoms?2.0:1.6}" text-anchor="middle" font-weight="bold" style="pointer-events:none;paint-order:stroke;stroke:#f0ede6;stroke-width:0.5px;" font-family="sans-serif">${t.name}</text>`;
});

// Choke points (text only)
const PASSES=[
  {name:'Northgate Pass',x:62,y:12},
  {name:"The King's Corridor",x:62,y:36},
  {name:'Southmaw Gap',x:62,y:59}
];
PASSES.forEach(p=>{
  html+=`<text x="${p.x}" y="${p.y-5}" fill="#8a7050" font-size="1.3" text-anchor="middle" font-style="italic" font-family="serif" opacity="0.8">${p.name}</text>`;
});

// Mountain range label
html+=`<text x="62" y="-46" fill="#8a7050" font-size="2.5" text-anchor="middle" font-style="italic" font-family="serif">The Ironspine Mountains</text>`;

// Scale bar (OS style)
html+=`<g transform="translate(200,110)">`;
html+=`<rect x="0" y="0" width="10" height="1" fill="#222"/>`;
html+=`<rect x="10" y="0" width="10" height="1" fill="#fff" stroke="#222" stroke-width="0.15"/>`;
html+=`<rect x="20" y="0" width="10" height="1" fill="#222"/>`;
html+=`<line x1="0" y1="-0.3" x2="0" y2="1.3" stroke="#222" stroke-width="0.15"/>`;
html+=`<line x1="10" y1="-0.3" x2="10" y2="1.3" stroke="#222" stroke-width="0.15"/>`;
html+=`<line x1="20" y1="-0.3" x2="20" y2="1.3" stroke="#222" stroke-width="0.15"/>`;
html+=`<line x1="30" y1="-0.3" x2="30" y2="1.3" stroke="#222" stroke-width="0.15"/>`;
html+=`<text x="0" y="3" fill="#333" font-size="1.2" text-anchor="middle" font-family="sans-serif">0</text>`;
html+=`<text x="10" y="3" fill="#333" font-size="1.2" text-anchor="middle" font-family="sans-serif">10</text>`;
html+=`<text x="20" y="3" fill="#333" font-size="1.2" text-anchor="middle" font-family="sans-serif">20</text>`;
html+=`<text x="30" y="3" fill="#333" font-size="1.2" text-anchor="middle" font-family="sans-serif">30 km</text>`;
html+=`</g>`;

// Compass (simple N arrow)
html+=`<g transform="translate(240,-42)">`;
html+=`<line x1="0" y1="3" x2="0" y2="-5" stroke="#333" stroke-width="0.3"/>`;
html+=`<polygon points="0,-6 -1.2,-3 1.2,-3" fill="#333"/>`;
html+=`<text x="0" y="-7.5" fill="#333" font-size="2" text-anchor="middle" font-weight="bold" font-family="sans-serif">N</text>`;
html+=`</g>`;

// Inject all generated SVG
const container=document.createElementNS(NS,'g');
container.innerHTML=html;
svg.appendChild(container);

/* ── Tooltip & hover ── */
document.querySelectorAll('.town-link').forEach(link=>{
  link.addEventListener('mouseenter',function(){
    const id=this.dataset.id, town=townMap[id];
    document.querySelectorAll(`.conn-${id}`).forEach(el=>{
      el.setAttribute('stroke','#ff6600');
      el.setAttribute('stroke-width','1.0');
    });
    document.querySelectorAll(`.dlbl-${id}`).forEach(el=>{
      el.setAttribute('fill','rgba(0,0,0,0.7)');
      el.setAttribute('font-size','1.6');
      el.setAttribute('font-weight','bold');
    });
    let resHtml='';
    if(town.resources && town.resources.length>0){
      resHtml='<div style="margin-top:8px;display:grid;grid-template-columns:1fr auto;gap:2px 14px;">';
      town.resources.forEach(r=>{
        resHtml+=`<span style="color:#555;">${r.name}</span><span style="text-align:right;color:#0a7;font-weight:600;">${r.stock}</span>`;
      });
      resHtml+='</div>';
    } else if(town.population===0){
      resHtml='<div style="margin-top:8px;color:#999;">Intel unavailable</div>';
    } else {
      resHtml='<div style="margin-top:8px;color:#888;">Global Market Access</div>';
    }
    tooltip.innerHTML=`
      <div style="font-size:14px;font-weight:bold;color:${town.side==='blue'?'#2266cc':'#cc3333'};margin-bottom:4px;">${town.name}</div>
      ${town.population>0?`<div>Population: <strong>${town.population.toLocaleString()}</strong></div>`:'<div style="color:#999;">Population: <em>Intel unavailable</em></div>'}
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
    const isCustomsConn=(n)=>{const t=townMap[n];return t&&t.name.includes('Customs');};
    document.querySelectorAll(`.conn-${id}`).forEach(el=>{
      const classes=el.className.baseVal||'';
      const connIds=classes.split(' ').filter(c=>c.startsWith('conn-')).map(c=>c.replace('conn-',''));
      const hasCustoms=connIds.some(cid=>isCustomsConn(cid));
      el.setAttribute('stroke',hasCustoms?'#e87d28':'#f0c848');
      el.setAttribute('stroke-width',hasCustoms?'0.8':'0.6');
    });
    document.querySelectorAll(`.dlbl-${id}`).forEach(el=>{
      el.setAttribute('fill','rgba(0,0,0,0.25)');
      el.setAttribute('font-size','1.2');
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

