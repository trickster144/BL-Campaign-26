<?php
session_start();
include "files/config.php";
include "files/auth.php";
$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
requireLogin();
$showDetails = isGreenTeam($user);
$userFaction = $user['team'] ?? 'grey';

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
        'resources' => [],
        'troops' => 0,
        'has_barracks' => false,
        'has_factory' => false,
        'has_power' => false
    ];
}

// Load resources for green team
if ($showDetails) {
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
}

// Troop counts per town (visible to own faction + green)
$troopRes = $conn->query("
    SELECT town_id, SUM(quantity) AS total
    FROM town_troops
    GROUP BY town_id
");
if ($troopRes) {
    while ($r = $troopRes->fetch_assoc()) {
        $tid = (int)$r['town_id'];
        for ($i = 0; $i < count($townsArr); $i++) {
            if ($townsArr[$i]['id'] === $tid) {
                $townsArr[$i]['troops'] = (int)$r['total'];
                break;
            }
        }
    }
}

// Building markers
$bRes = $conn->query("SELECT town_id FROM town_barracks WHERE level > 0");
if ($bRes) while ($r = $bRes->fetch_assoc()) {
    $tid = (int)$r['town_id'];
    for ($i = 0; $i < count($townsArr); $i++) {
        if ($townsArr[$i]['id'] === $tid) { $townsArr[$i]['has_barracks'] = true; break; }
    }
}
$fRes = $conn->query("SELECT town_id FROM town_munitions_factory WHERE level > 0");
if ($fRes) while ($r = $fRes->fetch_assoc()) {
    $tid = (int)$r['town_id'];
    for ($i = 0; $i < count($townsArr); $i++) {
        if ($townsArr[$i]['id'] === $tid) { $townsArr[$i]['has_factory'] = true; break; }
    }
}
$pRes = $conn->query("SELECT town_id FROM power_stations WHERE level > 0");
if ($pRes) while ($r = $pRes->fetch_assoc()) {
    $tid = (int)$r['town_id'];
    for ($i = 0; $i < count($townsArr); $i++) {
        if ($townsArr[$i]['id'] === $tid) { $townsArr[$i]['has_power'] = true; break; }
    }
}

// Road connections with type info
$roadArr = [];
$res = $conn->query("
    SELECT td.town_id_1, td.town_id_2, td.distance_km,
           COALESCE(r.road_type, 'mud') AS road_type,
           COALESCE(r.speed_limit, 30) AS speed_limit
    FROM town_distances td
    JOIN towns t1 ON td.town_id_1 = t1.id
    JOIN towns t2 ON td.town_id_2 = t2.id
    LEFT JOIN roads r ON r.town_id_1 = LEAST(td.town_id_1, td.town_id_2)
                     AND r.town_id_2 = GREATEST(td.town_id_1, td.town_id_2)
    WHERE t1.side = t2.side AND td.town_id_1 < td.town_id_2
");
while ($r = $res->fetch_assoc()) {
    $roadArr[] = [
        'from' => (int)$r['town_id_1'],
        'to' => (int)$r['town_id_2'],
        'km' => (float)$r['distance_km'],
        'type' => $r['road_type'],
        'speed' => (int)$r['speed_limit']
    ];
}

// Rail connections
$railArr = [];
$res = $conn->query("
    SELECT rl.town_id_1, rl.town_id_2, rl.rail_type, rl.speed_limit,
           td.distance_km
    FROM rail_lines rl
    JOIN town_distances td ON td.town_id_1 = LEAST(rl.town_id_1, rl.town_id_2)
                          AND td.town_id_2 = GREATEST(rl.town_id_1, rl.town_id_2)
    WHERE rl.town_id_1 < rl.town_id_2
");
while ($r = $res->fetch_assoc()) {
    $railArr[] = [
        'from' => (int)$r['town_id_1'],
        'to' => (int)$r['town_id_2'],
        'type' => $r['rail_type'],
        'speed' => (int)$r['speed_limit'],
        'km' => (float)$r['distance_km']
    ];
}

// Transmission (power) lines
$powerLineArr = [];
$res = $conn->query("
    SELECT tl.town_id_1, tl.town_id_2
    FROM transmission_lines tl
    WHERE tl.town_id_1 < tl.town_id_2
");
if ($res) while ($r = $res->fetch_assoc()) {
    $powerLineArr[] = [
        'from' => (int)$r['town_id_1'],
        'to' => (int)$r['town_id_2']
    ];
}

// Active troop movements
$moveArr = [];
$res = $conn->query("
    SELECT tm.faction, tm.from_town_id, tm.to_town_id, tm.quantity, tm.is_attack,
           tm.transport_type, tm.speed_kmh, tm.distance_km,
           UNIX_TIMESTAMP(tm.departed_at) AS dep_ts,
           UNIX_TIMESTAMP(tm.eta_at) AS eta_ts,
           UNIX_TIMESTAMP(NOW()) AS now_ts,
           wt.name AS weapon_name
    FROM troop_movements tm
    LEFT JOIN weapon_types wt ON tm.weapon_type_id = wt.id
    WHERE tm.arrived = 0
");
if ($res) while ($r = $res->fetch_assoc()) {
    $moveArr[] = [
        'faction' => $r['faction'],
        'from' => (int)$r['from_town_id'],
        'to' => (int)$r['to_town_id'],
        'qty' => (int)$r['quantity'],
        'attack' => (int)$r['is_attack'],
        'transport' => $r['transport_type'],
        'speed' => (float)$r['speed_kmh'],
        'dist' => (float)$r['distance_km'],
        'dep_ts' => (int)$r['dep_ts'],
        'eta_ts' => (int)$r['eta_ts'],
        'now_ts' => (int)$r['now_ts'],
        'weapon' => $r['weapon_name'] ?? 'Unarmed'
    ];
}

// Active vehicle trips
$tripArr = [];
$res = $conn->query("
    SELECT vt.from_town_id, vt.to_town_id, vt.cargo_amount,
           UNIX_TIMESTAMP(vt.departed_at) AS dep_ts,
           UNIX_TIMESTAMP(vt.eta_at) AS eta_ts,
           UNIX_TIMESTAMP(NOW()) AS now_ts,
           vty.name AS veh_name, t1.side AS faction
    FROM vehicle_trips vt
    JOIN vehicles v ON vt.vehicle_id = v.id
    JOIN vehicle_types vty ON v.vehicle_type_id = vty.id
    JOIN towns t1 ON vt.from_town_id = t1.id
    WHERE vt.arrived = 0
");
if ($res) while ($r = $res->fetch_assoc()) {
    $tripArr[] = [
        'from' => (int)$r['from_town_id'],
        'to' => (int)$r['to_town_id'],
        'dep_ts' => (int)$r['dep_ts'],
        'eta_ts' => (int)$r['eta_ts'],
        'now_ts' => (int)$r['now_ts'],
        'faction' => $r['faction'],
        'name' => $r['veh_name']
    ];
}

include "files/header.php";
include "files/sidebar.php";
?>
<style>
#map-wrapper{position:relative;height:80vh;min-height:550px;overflow:hidden;cursor:grab;background:#b8cdd6;border-radius:6px;border:2px solid #555;}
#map-wrapper.dragging{cursor:grabbing;}
#map-svg{width:100%;height:100%;display:block;}
#map-tooltip{display:none;position:fixed;pointer-events:none;z-index:9999;background:rgba(30,30,25,0.95);border:1px solid rgba(200,190,150,0.3);border-radius:6px;padding:12px 16px;color:#e8e2d0;font-size:12px;max-width:320px;box-shadow:0 4px 16px rgba(0,0,0,0.5);}
#map-controls{position:absolute;top:10px;right:10px;display:flex;flex-direction:column;gap:3px;z-index:100;}
#map-controls button{width:34px;height:34px;border:1px solid #666;border-radius:4px;background:rgba(40,40,35,0.9);color:#ccc;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s;}
#map-controls button:hover{background:rgba(60,60,50,0.95);color:#fff;}
#map-legend{position:absolute;bottom:10px;left:10px;background:rgba(35,35,30,0.94);border:1px solid rgba(180,170,130,0.25);border-radius:5px;padding:10px 14px;z-index:100;font-size:10px;color:#c8c0a8;}
#map-legend h6{margin:0 0 6px;font-size:9px;color:rgba(200,190,150,0.5);text-transform:uppercase;letter-spacing:1.5px;}
.legend-item{display:flex;align-items:center;gap:8px;margin:3px 0;line-height:1.2;}
.legend-swatch{flex-shrink:0;}
#map-layer-toggles{position:absolute;top:10px;left:10px;background:rgba(35,35,30,0.94);border:1px solid rgba(180,170,130,0.25);border-radius:5px;padding:8px 12px;z-index:100;font-size:10px;color:#c8c0a8;}
#map-layer-toggles label{display:flex;align-items:center;gap:6px;margin:2px 0;cursor:pointer;}
#map-layer-toggles input{accent-color:#6ac;}
a.town-link:hover .town-marker{filter:brightness(1.2) drop-shadow(0 0 4px rgba(255,220,80,0.6));}
.troop-badge{pointer-events:none;}
</style>
<!-- BEGIN #content -->
<div id="content" class="app-content">
  <h1 class="page-header">Strategic Map <small class="text-muted">— Hex Theatre</small></h1>
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
        <div id="map-layer-toggles">
          <label><input type="checkbox" id="lyr-roads" checked> Roads</label>
          <label><input type="checkbox" id="lyr-rail" checked> Rail</label>
          <label><input type="checkbox" id="lyr-power" checked> Power Lines</label>
          <label><input type="checkbox" id="lyr-troops" checked> Military</label>
          <label><input type="checkbox" id="lyr-hexcoord"> Hex Coords</label>
        </div>
        <div id="map-legend">
          <h6>Legend</h6>
          <div class="legend-item"><svg class="legend-swatch" width="22" height="8"><line x1="0" y1="4" x2="22" y2="4" stroke="#5a3a1a" stroke-width="4" stroke-linecap="round"/><line x1="0" y1="4" x2="22" y2="4" stroke="#c87830" stroke-width="2.5" stroke-linecap="round"/></svg>Road</div>
          <div class="legend-item"><svg class="legend-swatch" width="22" height="8"><line x1="0" y1="4" x2="22" y2="4" stroke="#222" stroke-width="2" stroke-dasharray="3,2"/><line x1="2" y1="2" x2="2" y2="6" stroke="#222" stroke-width="0.8"/><line x1="8" y1="2" x2="8" y2="6" stroke="#222" stroke-width="0.8"/><line x1="14" y1="2" x2="14" y2="6" stroke="#222" stroke-width="0.8"/><line x1="20" y1="2" x2="20" y2="6" stroke="#222" stroke-width="0.8"/></svg>Rail</div>
          <div class="legend-item"><svg class="legend-swatch" width="22" height="8"><line x1="0" y1="4" x2="22" y2="4" stroke="#e8b020" stroke-width="1.5" stroke-dasharray="1,3"/></svg>Power Line</div>
          <div class="legend-item"><span class="legend-swatch" style="display:inline-block;width:12px;height:12px;background:#8aaa6a;border:1px solid #6d8f50;border-radius:2px;"></span>Forest</div>
          <div class="legend-item"><span class="legend-swatch" style="display:inline-block;width:12px;height:12px;background:#b8ae8e;border:1px solid #a09470;border-radius:2px;"></span>Mountains</div>
          <div class="legend-item"><svg class="legend-swatch" width="22" height="8"><line x1="0" y1="4" x2="22" y2="4" stroke="#5a9ab8" stroke-width="2" stroke-linecap="round"/></svg>River</div>
          <div class="legend-item"><span class="legend-swatch" style="display:inline-block;width:12px;height:12px;background:#92bdd0;border:1px solid #7aa0b4;border-radius:2px;"></span>Water</div>
          <div class="legend-item"><svg class="legend-swatch" width="22" height="12"><circle cx="6" cy="6" r="5" fill="#c8daf0" stroke="#2a5090" stroke-width="1.5"/></svg>Blue Town</div>
          <div class="legend-item"><svg class="legend-swatch" width="22" height="12"><circle cx="6" cy="6" r="5" fill="#f0c8c8" stroke="#902a2a" stroke-width="1.5"/></svg>Red Town</div>
        </div>
        <svg id="map-svg" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet"></svg>
      </div>
    </div>
  </div>
</div>
<!-- END #content -->

<script>
(function(){
const towns = <?= json_encode($townsArr) ?>;
const roads = <?= json_encode($roadArr) ?>;
const rails = <?= json_encode($railArr) ?>;
const powerLines = <?= json_encode($powerLineArr) ?>;
const troopMoves = <?= json_encode($moveArr) ?>;
const vehicleTrips = <?= json_encode($tripArr) ?>;
const userFaction = <?= json_encode($userFaction) ?>;
const isGreen = <?= $showDetails ? 'true' : 'false' ?>;

const svg = document.getElementById('map-svg');
const tooltip = document.getElementById('map-tooltip');
const wrapper = document.getElementById('map-wrapper');
const townMap = {};
towns.forEach(t => townMap[t.id] = t);

/* ── viewBox state ── */
const FULL = {x:-100, y:-60, w:400, h:200};
let vb = {x:-10, y:-10, w:130, h:0};
function updateAspect(){
  const r = wrapper.getBoundingClientRect();
  vb.h = vb.w * (r.height / r.width);
  applyVB();
}
function applyVB(){ svg.setAttribute('viewBox', `${vb.x} ${vb.y} ${vb.w} ${vb.h}`); }
updateAspect();
window.addEventListener('resize', updateAspect);

/* ── Pan & Zoom ── */
let drag=false, dragStart={x:0,y:0}, vbStart={x:0,y:0};
function svgScale(){ return vb.w / wrapper.getBoundingClientRect().width; }
wrapper.addEventListener('mousedown', e=>{
  if(e.target.closest('.town-link')||e.target.closest('#map-controls')||e.target.closest('#map-legend')||e.target.closest('#map-layer-toggles')) return;
  drag=true; dragStart={x:e.clientX,y:e.clientY}; vbStart={x:vb.x,y:vb.y};
  wrapper.classList.add('dragging');
});
window.addEventListener('mousemove', e=>{ if(!drag) return; const s=svgScale(); vb.x=vbStart.x-(e.clientX-dragStart.x)*s; vb.y=vbStart.y-(e.clientY-dragStart.y)*s; applyVB(); });
window.addEventListener('mouseup', ()=>{ drag=false; wrapper.classList.remove('dragging'); });
wrapper.addEventListener('wheel', e=>{
  e.preventDefault();
  const rect=wrapper.getBoundingClientRect();
  const mx=(e.clientX-rect.left)/rect.width, my=(e.clientY-rect.top)/rect.height;
  const factor=e.deltaY>0?1.12:1/1.12;
  const nw=Math.max(20,Math.min(FULL.w,vb.w*factor)), nh=nw*(rect.height/rect.width);
  vb.x+=(vb.w-nw)*mx; vb.y+=(vb.h-nh)*my; vb.w=nw; vb.h=nh; applyVB();
},{passive:false});

/* Touch */
let touches0=null, touchVB0=null, touchDist0=0;
wrapper.addEventListener('touchstart', e=>{
  if(e.target.closest('.town-link')||e.target.closest('#map-controls')||e.target.closest('#map-legend')||e.target.closest('#map-layer-toggles')) return;
  if(e.touches.length===1){ drag=true; const t=e.touches[0]; dragStart={x:t.clientX,y:t.clientY}; vbStart={x:vb.x,y:vb.y}; wrapper.classList.add('dragging'); }
  else if(e.touches.length===2){ drag=false; touches0=[{x:e.touches[0].clientX,y:e.touches[0].clientY},{x:e.touches[1].clientX,y:e.touches[1].clientY}]; touchDist0=Math.hypot(touches0[1].x-touches0[0].x,touches0[1].y-touches0[0].y); touchVB0={x:vb.x,y:vb.y,w:vb.w,h:vb.h}; }
},{passive:false});
wrapper.addEventListener('touchmove', e=>{
  e.preventDefault();
  if(e.touches.length===1&&drag){ const t=e.touches[0],s=svgScale(); vb.x=vbStart.x-(t.clientX-dragStart.x)*s; vb.y=vbStart.y-(t.clientY-dragStart.y)*s; applyVB(); }
  else if(e.touches.length===2&&touches0){ const t=[{x:e.touches[0].clientX,y:e.touches[0].clientY},{x:e.touches[1].clientX,y:e.touches[1].clientY}]; const dist=Math.hypot(t[1].x-t[0].x,t[1].y-t[0].y); const factor=touchDist0/dist; const rect=wrapper.getBoundingClientRect(); const nw=Math.max(20,Math.min(FULL.w,touchVB0.w*factor)); const nh=nw*(rect.height/rect.width); const cmx=((t[0].x+t[1].x)/2-rect.left)/rect.width; const cmy=((t[0].y+t[1].y)/2-rect.top)/rect.height; vb.x=touchVB0.x+(touchVB0.w-nw)*cmx; vb.y=touchVB0.y+(touchVB0.h-nh)*cmy; vb.w=nw; vb.h=nh; applyVB(); }
},{passive:false});
wrapper.addEventListener('touchend', ()=>{ drag=false; touches0=null; wrapper.classList.remove('dragging'); });

/* Zoom buttons */
function zoomBy(f,cx,cy){ const rect=wrapper.getBoundingClientRect(); if(cx===undefined){cx=0.5;cy=0.5;} const nw=Math.max(20,Math.min(FULL.w,vb.w*f)),nh=nw*(rect.height/rect.width); vb.x+=(vb.w-nw)*cx; vb.y+=(vb.h-nh)*cy; vb.w=nw; vb.h=nh; applyVB(); }
document.getElementById('btn-zin').onclick=()=>zoomBy(1/1.4);
document.getElementById('btn-zout').onclick=()=>zoomBy(1.4);
document.getElementById('btn-fit').onclick=()=>{ const rect=wrapper.getBoundingClientRect(); vb.x=FULL.x;vb.y=FULL.y;vb.w=FULL.w;vb.h=FULL.w*(rect.height/rect.width);applyVB(); };
document.getElementById('btn-passes').onclick=()=>{ const rect=wrapper.getBoundingClientRect(); vb.w=60;vb.h=vb.w*(rect.height/rect.width); vb.x=62-vb.w/2;vb.y=36-vb.h/2;applyVB(); };

/* ══════════════════════════════════════════════════
   HEX MAP RENDERER — AWAW-style Strategic Map
   ══════════════════════════════════════════════════ */
const NS='http://www.w3.org/2000/svg';

// Hex params (pointy-top)
const HR = 5;
const HW = HR * Math.sqrt(3);
const ROW_H = HR * 1.5;

function hexPts(cx, cy) {
  const p = [];
  for (let i = 0; i < 6; i++) {
    const a = Math.PI/3*i - Math.PI/6;
    p.push((cx+HR*Math.cos(a)).toFixed(1)+','+(cy+HR*Math.sin(a)).toFixed(1));
  }
  return p.join(' ');
}

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

function thash(x, y) { let h = Math.sin(x*12.9898+y*78.233)*43758.5453; return h-Math.floor(h); }

// Terrain zones
const FOREST_ZONES = [
  {x:-20,y:20,rx:20,ry:14},{x:22,y:8,rx:12,ry:8},
  {x:120,y:14,rx:16,ry:10},{x:155,y:55,rx:18,ry:12},
  {x:-50,y:15,rx:10,ry:7},{x:200,y:45,rx:12,ry:8},
  {x:-20,y:60,rx:10,ry:8},{x:170,y:75,rx:11,ry:8},
  {x:8,y:48,rx:10,ry:6},{x:95,y:70,rx:8,ry:5},
  {x:230,y:30,rx:10,ry:7},{x:-35,y:55,rx:8,ry:5},
  {x:140,y:40,rx:7,ry:4},{x:185,y:55,rx:7,ry:4},
  {x:-55,y:80,rx:7,ry:5},{x:210,y:15,rx:6,ry:4},
  {x:100,y:5,rx:7,ry:5},{x:160,y:90,rx:7,ry:5}
];

function getTerrain(cx, cy) {
  const md = Math.abs(cx - 62);
  if (md < 7) return thash(cx*0.5,cy*0.5)<0.45?'mt_high':'mt';
  if (md < 13) return thash(cx*0.7,cy*0.7)<0.3?'mt':'hill';
  for (const f of FOREST_ZONES) {
    const dx=(cx-f.x)/f.rx, dy=(cy-f.y)/f.ry;
    if (dx*dx+dy*dy<1) return thash(cx,cy)<0.35?'forest_d':'forest';
  }
  const v = thash(cx*0.8,cy*0.8);
  if (v<0.07) return 'forest_l';
  return v<0.5?'open':'open2';
}

// AWAW-style terrain colours
const TCOL = {
  open:'#e0dcc0', open2:'#ddd8b8',
  forest_l:'#c0cc9e', forest:'#90b070', forest_d:'#6e9454',
  hill:'#d0c8a0', mt:'#c0b89a', mt_high:'#a89880'
};

function roadPath(x1,y1,x2,y2,seed){
  const mx=(x1+x2)/2,my=(y1+y2)/2,dx=x2-x1,dy=y2-y1,len=Math.sqrt(dx*dx+dy*dy)||1;
  const px=-dy/len,py=dx/len,h=Math.sin(seed)*Math.min(3,len*0.08);
  return `M${x1},${y1} Q${(mx+px*h).toFixed(1)},${(my+py*h).toFixed(1)} ${x2},${y2}`;
}

// Build SVG layers as strings
let htmlBase='', htmlHex='', htmlHexCoords='', htmlLakes='', htmlRivers='';
let htmlRoads='', htmlRail='', htmlPower='', htmlTowns='', htmlMilitary='';
let htmlLabels='', htmlChrome='';

// ── 1. Sea background ──
htmlBase += `<rect x="${FULL.x}" y="${FULL.y}" width="${FULL.w}" height="${FULL.h}" fill="#92bdd0"/>`;

// ── 2. Landmass outline ──
const COAST='M-72,-38 C-58,-46 -35,-44 -15,-43 C5,-42 25,-41 42,-42 C52,-43 58,-46 62,-47 C66,-46 72,-43 85,-42 C105,-41 125,-44 148,-42 C168,-40 190,-42 210,-38 C228,-35 242,-33 254,-28 C262,-20 266,-10 266,0 C266,8 266,14 265,18 C264,20 262,22 261,23 L259,25 L261,27 C263,29 265,32 266,36 C267,48 267,60 264,74 C260,86 253,97 243,104 C233,110 223,113 210,114 C192,116 175,115 158,114 C140,113 122,116 105,118 C88,119 72,115 55,117 C38,119 20,118 5,115 C-10,112 -25,108 -38,102 C-50,96 -58,90 -64,82 C-70,72 -74,62 -76,52 C-78,44 -78,36 -77,30 L-76,28 L-78,26 C-80,24 -80,22 -80,20 C-79,14 -78,4 -76,-6 C-74,-18 -73,-30 -72,-38Z';
htmlBase += `<path d="${COAST}" fill="#ddd8b8" stroke="#8aa0a8" stroke-width="0.5"/>`;
htmlBase += `<defs><clipPath id="lc"><path d="${COAST}"/></clipPath></defs>`;

// ── 3. Hex grid with terrain (clipped to land) ──
htmlHex += `<g clip-path="url(#lc)">`;
let hexRow=0;
for (let cy = FULL.y - HR; cy <= FULL.y+FULL.h+HR; cy += ROW_H, hexRow++) {
  const off = (hexRow%2)?HW/2:0;
  let hexCol=0;
  for (let cx = FULL.x-HR+off; cx <= FULL.x+FULL.w+HR; cx += HW, hexCol++) {
    const t = getTerrain(cx, cy);
    htmlHex += `<polygon points="${hexPts(cx,cy)}" fill="${TCOL[t]}" stroke="#b0a888" stroke-width="0.2"/>`;
    // Hex coordinates (hidden by default)
    const hcol = hexCol, hrow = hexRow;
    htmlHexCoords += `<text x="${cx}" y="${cy+0.5}" fill="rgba(80,60,30,0.25)" font-size="1.0" text-anchor="middle" font-family="monospace" class="hex-coord" style="display:none;">${hcol}.${hrow}</text>`;
  }
}
htmlHex += `</g>`;

// ── 4. Lakes ──
const LAKES = [
  {x:48,y:-6,rx:4,ry:2.5,name:'Highland Loch'},
  {x:158,y:72,rx:5,ry:3,name:'Ember Reservoir'},
  {x:-35,y:30,rx:2,ry:1.5,name:'Mill Pond'},
  {x:190,y:60,rx:3,ry:2,name:'Beacon Tarn'}
];
LAKES.forEach(l => {
  htmlLakes += `<ellipse cx="${l.x}" cy="${l.y}" rx="${l.rx}" ry="${l.ry}" fill="#92bdd0" stroke="#6a9ab4" stroke-width="0.3"/>`;
  htmlLakes += `<text x="${l.x}" y="${l.y+l.ry+1.8}" fill="#3a7a9a" font-size="1.0" text-anchor="middle" font-style="italic" font-family="serif">${l.name}</text>`;
});

// ── 5. Rivers ──
const RIVERS = [
  {name:'Silvervein River',pts:[[58,-5],[50,4],[38,12],[22,22],[5,32],[-18,42],[-45,50],[-74,56]],w:1.2},
  {name:'Ember River',pts:[[67,36],[82,38],[102,41],[130,46],[165,52],[200,58],[240,64],[260,68]],w:1.4},
  {name:'Southflow River',pts:[[62,62],[64,70],[68,80],[70,90],[66,100],[60,112],[55,120]],w:0.9},
  {name:'Frost Creek',pts:[[56,-12],[48,-20],[38,-28],[25,-38],[12,-48]],w:0.7}
];
RIVERS.forEach(r => {
  const d = bezierPath(r.pts);
  htmlRivers += `<path d="${d}" fill="none" stroke="#5a9ab8" stroke-width="${r.w+0.8}" stroke-linecap="round" opacity="0.4"/>`;
  htmlRivers += `<path d="${d}" fill="none" stroke="#5a9ab8" stroke-width="${r.w}" stroke-linecap="round"/>`;
});
const TRIBS = [
  [[42,8],[30,2],[18,-4]],[[48,14],[40,20]],
  [[78,40],[88,48],[95,56]],[[110,44],[118,52]],
  [[65,74],[72,68]],[[58,90],[48,88]]
];
TRIBS.forEach(pts => {
  htmlRivers += `<path d="${bezierPath(pts)}" fill="none" stroke="#5a9ab8" stroke-width="0.3" stroke-linecap="round" opacity="0.5"/>`;
});
RIVERS.forEach(r => {
  const mid = Math.floor(r.pts.length/2), mp = r.pts[mid];
  let angle=0;
  if(mid>0){ const prev=r.pts[mid-1]; angle=Math.atan2(mp[1]-prev[1],mp[0]-prev[0])*180/Math.PI; if(angle>90) angle-=180; if(angle<-90) angle+=180; }
  htmlRivers += `<text x="${mp[0]}" y="${mp[1]-1.5}" fill="#3a7a9a" font-size="1.2" text-anchor="middle" font-style="italic" font-family="serif" transform="rotate(${angle.toFixed(1)},${mp[0]},${mp[1]-1.5})">${r.name}</text>`;
});

// ── 6. Road network (AWAW-style brown road casings) ──
const ROAD_COL = {mud:'#b89860', gravel:'#c8a050', asphalt:'#c87830', dual:'#a03020'};
const ROAD_CASE = {mud:'#6a5028', gravel:'#5a3a1a', asphalt:'#4a2a0a', dual:'#2a0a0a'};
const ROAD_W = {mud:0.7, gravel:0.9, asphalt:1.1, dual:1.5};
// Casing pass
roads.forEach(d => {
  const t1=townMap[d.from], t2=townMap[d.to]; if(!t1||!t2) return;
  const p = roadPath(t1.x,t1.y,t2.x,t2.y, d.from*127.1+d.to*311.7);
  const w = ROAD_W[d.type]||0.7;
  htmlRoads += `<path d="${p}" fill="none" stroke="${ROAD_CASE[d.type]||'#5a3a1a'}" stroke-width="${w+0.6}" stroke-linecap="round" class="layer-road"/>`;
});
// Fill pass
roads.forEach(d => {
  const t1=townMap[d.from], t2=townMap[d.to]; if(!t1||!t2) return;
  const p = roadPath(t1.x,t1.y,t2.x,t2.y, d.from*127.1+d.to*311.7);
  const w = ROAD_W[d.type]||0.7;
  htmlRoads += `<path class="layer-road conn conn-${d.from} conn-${d.to}" data-road-type="${d.type}" d="${p}" fill="none" stroke="${ROAD_COL[d.type]||'#b89860'}" stroke-width="${w}" stroke-linecap="round"/>`;
});
// Distance labels on roads
roads.forEach(d => {
  const t1=townMap[d.from], t2=townMap[d.to]; if(!t1||!t2) return;
  const mx=(t1.x+t2.x)/2, my=(t1.y+t2.y)/2;
  let angle=Math.atan2(t2.y-t1.y,t2.x-t1.x)*180/Math.PI;
  if(angle>90)angle-=180; if(angle<-90)angle+=180;
  htmlRoads += `<text class="layer-road dist-label dlbl-${d.from} dlbl-${d.to}" x="${mx}" y="${my}" fill="rgba(60,40,20,0.35)" font-size="1.1" text-anchor="middle" transform="rotate(${angle.toFixed(1)},${mx.toFixed(1)},${my.toFixed(1)})" dy="-0.8" style="pointer-events:none;" font-family="sans-serif">${d.km.toFixed(1)}km</text>`;
});

// ── 7. Rail network (AWAW black dashed + cross-ties) ──
rails.forEach(d => {
  const t1=townMap[d.from], t2=townMap[d.to]; if(!t1||!t2) return;
  const ox = (t2.y-t1.y)*0.03, oy = -(t2.x-t1.x)*0.03; // offset from road
  const p = `M${t1.x+ox},${t1.y+oy} L${t2.x+ox},${t2.y+oy}`;
  const isElec = d.type==='electrified';
  htmlRail += `<line x1="${t1.x+ox}" y1="${t1.y+oy}" x2="${t2.x+ox}" y2="${t2.y+oy}" stroke="${isElec?'#1a1a6a':'#222'}" stroke-width="0.7" class="layer-rail"/>`;
  // Cross-ties
  const dx=t2.x-t1.x+ox*2, dy=t2.y-t1.y+oy*2, len=Math.sqrt(dx*dx+dy*dy);
  if(len>0){
    const nx=-dy/len*0.6, ny=dx/len*0.6;
    const step = 2.5;
    for(let s=step; s<len-1; s+=step){
      const frac=s/len;
      const cx=t1.x+ox+dx*frac, cy2=t1.y+oy+dy*frac;
      htmlRail += `<line x1="${cx-nx}" y1="${cy2-ny}" x2="${cx+nx}" y2="${cy2+ny}" stroke="${isElec?'#1a1a6a':'#333'}" stroke-width="0.3" class="layer-rail"/>`;
    }
  }
  if(isElec){
    htmlRail += `<line x1="${t1.x+ox}" y1="${t1.y+oy}" x2="${t2.x+ox}" y2="${t2.y+oy}" stroke="#3838c0" stroke-width="0.3" stroke-dasharray="0.5,2" class="layer-rail"/>`;
  }
});

// ── 8. Power/Transmission lines (yellow dotted) ──
powerLines.forEach(d => {
  const t1=townMap[d.from], t2=townMap[d.to]; if(!t1||!t2) return;
  const ox = -(t2.y-t1.y)*0.04, oy = (t2.x-t1.x)*0.04; // offset opposite of rail
  htmlPower += `<line x1="${t1.x+ox}" y1="${t1.y+oy}" x2="${t2.x+ox}" y2="${t2.y+oy}" stroke="#d0a010" stroke-width="0.5" stroke-dasharray="0.8,2.5" class="layer-power"/>`;
  // Pylon dots along the line
  const dx=t2.x-t1.x, dy=t2.y-t1.y, len=Math.sqrt(dx*dx+dy*dy);
  if(len>0){
    const step=8;
    for(let s=step; s<len; s+=step){
      const frac=s/len;
      htmlPower += `<circle cx="${t1.x+ox+dx*frac}" cy="${t1.y+oy+dy*frac}" r="0.3" fill="#d0a010" class="layer-power"/>`;
    }
  }
});

// ── 9. Choke points / Passes ──
const PASSES=[
  {name:'Northgate Pass',x:62,y:12},
  {name:"The King's Corridor",x:62,y:36},
  {name:'Southmaw Gap',x:62,y:59}
];
PASSES.forEach(p => {
  htmlLabels += `<text x="${p.x}" y="${p.y-5}" fill="#6a5838" font-size="1.4" text-anchor="middle" font-style="italic" font-family="serif" opacity="0.9">${p.name}</text>`;
});
htmlLabels += `<text x="62" y="-46" fill="#7a6848" font-size="2.2" text-anchor="middle" font-style="italic" font-family="serif" opacity="0.8">The Ironspine Mountains</text>`;

// Faction territory labels
const blueTowns = towns.filter(t=>t.side==='blue'&&!t.name.includes('Customs'));
const redTowns = towns.filter(t=>t.side==='red'&&!t.name.includes('Customs'));
if(blueTowns.length){
  const cx=blueTowns.reduce((s,t)=>s+t.x,0)/blueTowns.length;
  const cy=Math.min(...blueTowns.map(t=>t.y))-8;
  htmlLabels+=`<text x="${cx}" y="${cy}" fill="rgba(40,70,120,0.35)" font-size="3.5" text-anchor="middle" font-weight="bold" font-family="serif" letter-spacing="4">BLUE TERRITORY</text>`;
}
if(redTowns.length){
  const cx=redTowns.reduce((s,t)=>s+t.x,0)/redTowns.length;
  const cy=Math.min(...redTowns.map(t=>t.y))-8;
  htmlLabels+=`<text x="${cx}" y="${cy}" fill="rgba(120,40,40,0.35)" font-size="3.5" text-anchor="middle" font-weight="bold" font-family="serif" letter-spacing="4">RED TERRITORY</text>`;
}

// ── 10. Town markers (AWAW-style with building icons) ──
towns.forEach(t => {
  const isCustoms = t.name.includes('Customs');
  const isBlue = t.side==='blue';
  const canSeeTroops = isGreen || t.side===userFaction;

  htmlTowns += `<a href="town_view.php?id=${t.id}" class="town-link" data-id="${t.id}">`;
  if (isCustoms) {
    htmlTowns += `<rect x="${t.x-2.5}" y="${t.y-2.5}" width="5" height="5" rx="0.6" fill="#f0ecdc" stroke="#333" stroke-width="0.5" class="town-marker" style="cursor:pointer;"/>`;
    htmlTowns += `<text x="${t.x}" y="${t.y+0.8}" fill="#333" font-size="2.2" text-anchor="middle" style="pointer-events:none;">⚓</text>`;
  } else {
    const fill = isBlue ? '#c8daf0' : '#f0c8c8';
    const stroke = isBlue ? '#2a5090' : '#902a2a';
    // Outer ring for town size
    const r = 2.0;
    htmlTowns += `<circle cx="${t.x}" cy="${t.y}" r="${r+0.5}" fill="none" stroke="${stroke}" stroke-width="0.3" opacity="0.4" class="town-marker"/>`;
    htmlTowns += `<circle cx="${t.x}" cy="${t.y}" r="${r}" fill="${fill}" stroke="${stroke}" stroke-width="0.5" class="town-marker" style="cursor:pointer;"/>`;
    // Building pips
    let pips = '';
    if(t.has_barracks) pips += '⚔';
    if(t.has_factory) pips += '🏭';
    if(t.has_power) pips += '⚡';
    if(pips) htmlTowns += `<text x="${t.x+r+1}" y="${t.y+0.5}" fill="#444" font-size="1.5" style="pointer-events:none;">${pips}</text>`;
  }
  htmlTowns += `</a>`;

  // Town name
  const yOff = isCustoms ? -4.2 : -3.5;
  const fontSize = isCustoms ? 2.0 : 1.5;
  htmlTowns += `<text x="${t.x}" y="${t.y+yOff}" fill="#1a1a18" font-size="${fontSize}" text-anchor="middle" font-weight="bold" style="pointer-events:none;paint-order:stroke;stroke:#ddd8b8;stroke-width:0.8px;" font-family="sans-serif">${t.name}</text>`;

  // Troop count badge
  if(canSeeTroops && t.troops > 0) {
    const bx = t.x - (isCustoms?3:2.5), by = t.y + (isCustoms?3:2.5);
    htmlMilitary += `<g class="layer-troops troop-badge">`;
    htmlMilitary += `<rect x="${bx-2}" y="${by-1.5}" width="4" height="3" rx="0.5" fill="${isBlue?'#1a3a6a':'#6a1a1a'}" stroke="${isBlue?'#4a7acc':'#cc4a4a'}" stroke-width="0.3" opacity="0.9"/>`;
    htmlMilitary += `<text x="${bx}" y="${by+0.6}" fill="#fff" font-size="1.3" text-anchor="middle" font-weight="bold" font-family="sans-serif">${t.troops > 999 ? Math.round(t.troops/1000)+'k' : t.troops}</text>`;
    htmlMilitary += `</g>`;
  }
});

// ── 11. Active troop movements (animated arrows) ──
troopMoves.forEach(m => {
  const t1=townMap[m.from], t2=townMap[m.to];
  if(!t1||!t2) return;
  const canSee = isGreen || m.faction===userFaction;
  if(!canSee) return;
  const total = m.eta_ts - m.dep_ts;
  const elapsed = m.now_ts - m.dep_ts;
  const progress = total > 0 ? Math.min(1, Math.max(0, elapsed/total)) : 1;
  const px = t1.x + (t2.x-t1.x)*progress;
  const py = t1.y + (t2.y-t1.y)*progress;
  const isBlue = m.faction==='blue';
  const col = isBlue ? '#2a6acc' : '#cc3a2a';
  const bgCol = isBlue ? '#1a3a6a' : '#6a1a1a';

  // Movement path line
  htmlMilitary += `<line x1="${t1.x}" y1="${t1.y}" x2="${t2.x}" y2="${t2.y}" stroke="${col}" stroke-width="0.4" stroke-dasharray="1,1.5" opacity="0.5" class="layer-troops"/>`;

  // Unit marker at current position
  htmlMilitary += `<g class="layer-troops">`;
  if(m.attack) {
    // Attack arrow
    htmlMilitary += `<polygon points="${px},${py-2} ${px-1.5},${py+1} ${px+1.5},${py+1}" fill="${col}" stroke="#fff" stroke-width="0.2" opacity="0.9"/>`;
  } else {
    htmlMilitary += `<circle cx="${px}" cy="${py}" r="1.5" fill="${bgCol}" stroke="${col}" stroke-width="0.3" opacity="0.9"/>`;
  }
  htmlMilitary += `<text x="${px}" y="${py+0.5}" fill="#fff" font-size="1.0" text-anchor="middle" font-weight="bold" style="pointer-events:none;">${m.qty}</text>`;
  htmlMilitary += `</g>`;
});

// ── 12. Active vehicle trips (small truck icons on route) ──
vehicleTrips.forEach(v => {
  const t1=townMap[v.from], t2=townMap[v.to];
  if(!t1||!t2) return;
  const canSee = isGreen || v.faction===userFaction;
  if(!canSee) return;
  const total = v.eta_ts - v.dep_ts;
  const elapsed = v.now_ts - v.dep_ts;
  const progress = total > 0 ? Math.min(1, Math.max(0, elapsed/total)) : 1;
  const px = t1.x + (t2.x-t1.x)*progress;
  const py = t1.y + (t2.y-t1.y)*progress;
  htmlMilitary += `<g class="layer-troops">`;
  htmlMilitary += `<rect x="${px-1}" y="${py-0.8}" width="2" height="1.6" rx="0.3" fill="#556" stroke="#889" stroke-width="0.2"/>`;
  htmlMilitary += `</g>`;
});

// ── 13. Scale bar ──
htmlChrome += `<g transform="translate(200,110)">`;
htmlChrome += `<rect x="0" y="0" width="10" height="1" fill="#333"/>`;
htmlChrome += `<rect x="10" y="0" width="10" height="1" fill="#f0ecdc" stroke="#333" stroke-width="0.15"/>`;
htmlChrome += `<rect x="20" y="0" width="10" height="1" fill="#333"/>`;
[0,10,20,30].forEach(v => htmlChrome += `<line x1="${v}" y1="-0.3" x2="${v}" y2="1.3" stroke="#333" stroke-width="0.15"/>`);
htmlChrome += `<text x="0" y="3" fill="#333" font-size="1.2" text-anchor="middle" font-family="sans-serif">0</text>`;
htmlChrome += `<text x="10" y="3" fill="#333" font-size="1.2" text-anchor="middle" font-family="sans-serif">10</text>`;
htmlChrome += `<text x="20" y="3" fill="#333" font-size="1.2" text-anchor="middle" font-family="sans-serif">20</text>`;
htmlChrome += `<text x="30" y="3.2" fill="#333" font-size="1.2" text-anchor="middle" font-family="sans-serif">30 km</text>`;
htmlChrome += `</g>`;

// ── 14. Compass rose ──
htmlChrome += `<g transform="translate(240,-42)">`;
htmlChrome += `<circle cx="0" cy="-1" r="5" fill="rgba(240,236,220,0.7)" stroke="#666" stroke-width="0.2"/>`;
htmlChrome += `<line x1="0" y1="3" x2="0" y2="-5" stroke="#333" stroke-width="0.3"/>`;
htmlChrome += `<line x1="-3" y1="-1" x2="3" y2="-1" stroke="#999" stroke-width="0.15"/>`;
htmlChrome += `<polygon points="0,-5.5 -0.8,-3 0.8,-3" fill="#333"/>`;
htmlChrome += `<polygon points="0,3.5 -0.8,1 0.8,1" fill="#999"/>`;
htmlChrome += `<text x="0" y="-6.5" fill="#333" font-size="1.8" text-anchor="middle" font-weight="bold" font-family="sans-serif">N</text>`;
htmlChrome += `</g>`;

// Assemble SVG
const container = document.createElementNS(NS,'g');
container.innerHTML = htmlBase + htmlHex + htmlHexCoords + htmlLakes + htmlRivers + htmlLabels + htmlRoads + htmlRail + htmlPower + htmlTowns + htmlMilitary + htmlChrome;
svg.appendChild(container);

/* ── Layer toggles ── */
function toggleLayer(cls, vis){
  document.querySelectorAll('.'+cls).forEach(el => el.style.display = vis ? '' : 'none');
}
document.getElementById('lyr-roads').addEventListener('change', e => toggleLayer('layer-road', e.target.checked));
document.getElementById('lyr-rail').addEventListener('change', e => toggleLayer('layer-rail', e.target.checked));
document.getElementById('lyr-power').addEventListener('change', e => toggleLayer('layer-power', e.target.checked));
document.getElementById('lyr-troops').addEventListener('change', e => toggleLayer('layer-troops', e.target.checked));
document.getElementById('lyr-hexcoord').addEventListener('change', e => {
  document.querySelectorAll('.hex-coord').forEach(el => el.style.display = e.target.checked ? '' : 'none');
});

/* ── Tooltip & hover ── */
document.querySelectorAll('.town-link').forEach(link => {
  link.addEventListener('mouseenter', function(){
    const id=this.dataset.id, town=townMap[id];
    // Highlight connections
    document.querySelectorAll(`.conn-${id}`).forEach(el => { el.setAttribute('stroke','#ff6600'); el.setAttribute('stroke-width','1.4'); });
    document.querySelectorAll(`.dlbl-${id}`).forEach(el => { el.setAttribute('fill','rgba(60,40,20,0.8)'); el.setAttribute('font-size','1.5'); el.setAttribute('font-weight','bold'); });

    const isBlue = town.side==='blue';
    const canSeeTroops = isGreen || town.side===userFaction;
    let html = `<div style="font-size:14px;font-weight:bold;color:${isBlue?'#6699dd':'#dd5544'};margin-bottom:4px;">${town.name}</div>`;

    if(town.population>0) html += `<div>Pop: <strong>${town.population.toLocaleString()}</strong></div>`;
    else html += `<div style="color:#888;">Pop: <em>Intel unavailable</em></div>`;

    // Building info
    let bldgs = [];
    if(town.has_barracks) bldgs.push('⚔ Barracks');
    if(town.has_factory) bldgs.push('🏭 Factory');
    if(town.has_power) bldgs.push('⚡ Power');
    if(bldgs.length) html += `<div style="margin-top:4px;color:#aaa;">${bldgs.join(' · ')}</div>`;

    if(canSeeTroops && town.troops > 0) {
      html += `<div style="margin-top:4px;">🛡️ Garrison: <strong style="color:#e8c040;">${town.troops.toLocaleString()}</strong> troops</div>`;
    }

    if(town.resources && town.resources.length>0){
      html += '<div style="margin-top:6px;display:grid;grid-template-columns:1fr auto;gap:2px 14px;">';
      town.resources.forEach(r => {
        html += `<span style="color:#bbb8a8;">${r.name}</span><span style="text-align:right;color:#6ac;font-weight:600;">${Math.floor(r.stock)}</span>`;
      });
      html += '</div>';
    }

    tooltip.innerHTML = html;
    tooltip.style.display='block';
  });

  link.addEventListener('mousemove', function(e){
    const tt=tooltip;
    let lx=e.clientX+15, ly=e.clientY+15;
    if(lx+320>window.innerWidth) lx=e.clientX-320-15;
    if(ly+tt.offsetHeight>window.innerHeight) ly=e.clientY-tt.offsetHeight-15;
    tt.style.left=lx+'px'; tt.style.top=ly+'px';
  });

  link.addEventListener('mouseleave', function(){
    const id=this.dataset.id;
    const isCustomsConn=n=>{const t=townMap[n];return t&&t.name.includes('Customs');};
    document.querySelectorAll(`.conn-${id}`).forEach(el => {
      const rt = el.dataset.roadType || 'mud';
      el.setAttribute('stroke', ROAD_COL[rt] || '#b89860');
      el.setAttribute('stroke-width', ROAD_W[rt] || '0.7');
    });
    document.querySelectorAll(`.dlbl-${id}`).forEach(el => { el.setAttribute('fill','rgba(60,40,20,0.35)'); el.setAttribute('font-size','1.1'); el.removeAttribute('font-weight'); });
    tooltip.style.display='none';
  });
});

// Auto-refresh every 30 seconds for troop positions
setTimeout(()=>location.reload(), 30000);
})();
</script>
<?php
include "files/scripts.php";
?>
