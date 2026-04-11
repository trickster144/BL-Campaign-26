import React from 'react';

interface HexTileProps {
  q: number;
  r: number;
  size: number;
  team: 'blue' | 'red' | null;
  terrain?: string;
  label?: string;
  hasArmy?: boolean;
  hasBuilding?: boolean;
  isSelected?: boolean;
  fogOfWar?: boolean;
  onClick?: (q: number, r: number) => void;
}

// Flat-top hex: axial → pixel
function hexToPixel(q: number, r: number, size: number) {
  const x = size * (3 / 2) * q;
  const y = size * (Math.sqrt(3) / 2 * q + Math.sqrt(3) * r);
  return { x, y };
}

function hexPoints(size: number): string {
  const pts: string[] = [];
  for (let i = 0; i < 6; i++) {
    const angle = (Math.PI / 180) * (60 * i);
    pts.push(`${size * Math.cos(angle)},${size * Math.sin(angle)}`);
  }
  return pts.join(' ');
}

const terrainColors: Record<string, string> = {
  plains: '#2d5a1e',
  forest: '#1a3d0f',
  mountain: '#5c5c5c',
  water: '#1a4c7a',
  desert: '#8b7d3c',
  urban: '#3a3a4e',
};

const HexTile: React.FC<HexTileProps> = ({
  q, r, size, team, terrain = 'plains', label,
  hasArmy, hasBuilding, isSelected, fogOfWar, onClick,
}) => {
  const { x, y } = hexToPixel(q, r, size);

  let fillColor = terrainColors[terrain] || terrainColors.plains;
  if (team === 'blue') fillColor = blendColor(fillColor, '#1e90ff', 0.3);
  if (team === 'red') fillColor = blendColor(fillColor, '#dc3545', 0.3);
  if (fogOfWar) fillColor = '#2a2a3a';

  const strokeColor = isSelected ? '#ffd700' : team === 'blue' ? '#1e90ff44' : team === 'red' ? '#dc354544' : '#ffffff15';
  const strokeWidth = isSelected ? 2.5 : 1;

  return (
    <g
      transform={`translate(${x}, ${y})`}
      onClick={() => onClick?.(q, r)}
      style={{ cursor: onClick ? 'pointer' : 'default' }}
    >
      <polygon
        points={hexPoints(size)}
        fill={fillColor}
        stroke={strokeColor}
        strokeWidth={strokeWidth}
        opacity={fogOfWar ? 0.4 : 0.9}
      />

      {/* Army marker */}
      {hasArmy && !fogOfWar && (
        <circle cx={0} cy={-size * 0.3} r={size * 0.15} fill="#ffd700" stroke="#000" strokeWidth={1} />
      )}

      {/* Building marker */}
      {hasBuilding && !fogOfWar && (
        <rect
          x={-size * 0.12}
          y={size * 0.15}
          width={size * 0.24}
          height={size * 0.2}
          fill="#aaa"
          stroke="#000"
          strokeWidth={0.5}
        />
      )}

      {/* Label */}
      {label && !fogOfWar && (
        <text
          textAnchor="middle"
          dy="0.35em"
          fontSize={size * 0.22}
          fill="#e0e0e0"
          fontFamily="'Oswald', sans-serif"
          style={{ pointerEvents: 'none', textShadow: '0 1px 3px rgba(0,0,0,0.8)' }}
        >
          {label}
        </text>
      )}

      {/* Fog overlay */}
      {fogOfWar && (
        <polygon
          points={hexPoints(size)}
          fill="url(#fogPattern)"
          opacity={0.5}
          style={{ pointerEvents: 'none' }}
        />
      )}

      {/* Hover tooltip via title */}
      <title>
        {fogOfWar
          ? 'Unknown territory'
          : `[${q},${r}] ${label || terrain}${team ? ` (${team})` : ''}`}
      </title>
    </g>
  );
};

function blendColor(base: string, overlay: string, alpha: number): string {
  const parse = (hex: string) => {
    const h = hex.replace('#', '');
    return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
  };
  const b = parse(base);
  const o = parse(overlay);
  const r = Math.round(b[0] * (1 - alpha) + o[0] * alpha);
  const g = Math.round(b[1] * (1 - alpha) + o[1] * alpha);
  const bl = Math.round(b[2] * (1 - alpha) + o[2] * alpha);
  return `rgb(${r},${g},${bl})`;
}

export default HexTile;
