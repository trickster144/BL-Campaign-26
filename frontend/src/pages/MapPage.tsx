import React, { useEffect, useState, useCallback, useMemo } from 'react';
import { Container, Row, Col, Card, Spinner, Modal, Badge, Button, Form } from 'react-bootstrap';
import { MapContainer, SVGOverlay, useMap } from 'react-leaflet';
import { CRS, LatLngBounds } from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { locationAPI, armyAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import type { Location, Army } from '../types';

// ── Hex math ─────────────────────────────────────────────────────────────────
const HEX_SIZE = 50;

function hexToPixel(q: number, r: number): [number, number] {
  const x = HEX_SIZE * (3 / 2) * q;
  const y = HEX_SIZE * (Math.sqrt(3) / 2 * q + Math.sqrt(3) * r);
  return [x, y];
}

function hexCorners(cx: number, cy: number): string {
  const pts: string[] = [];
  for (let i = 0; i < 6; i++) {
    const angle = (Math.PI / 180) * (60 * i);
    pts.push(`${cx + HEX_SIZE * Math.cos(angle)},${cy + HEX_SIZE * Math.sin(angle)}`);
  }
  return pts.join(' ');
}

const terrainColors: Record<string, string> = {
  city: '#3a3a5e',
  town: '#2d4a3a',
  village: '#2d5a1e',
  outpost: '#4a3a2d',
  base: '#3a3a4e',
  port: '#1a4c7a',
  airfield: '#3a4a3a',
};

function teamTint(baseColor: string, team: string | null, alpha: number = 0.3): string {
  if (!team) return baseColor;
  const overlay = team === 'blue' ? [30, 144, 255] : [220, 53, 69];
  const hex = baseColor.replace('#', '');
  const base = [
    parseInt(hex.slice(0, 2), 16),
    parseInt(hex.slice(2, 4), 16),
    parseInt(hex.slice(4, 6), 16),
  ];
  const r = Math.round(base[0] * (1 - alpha) + overlay[0] * alpha);
  const g = Math.round(base[1] * (1 - alpha) + overlay[1] * alpha);
  const b = Math.round(base[2] * (1 - alpha) + overlay[2] * alpha);
  return `rgb(${r},${g},${b})`;
}

// ── Map auto-fit ─────────────────────────────────────────────────────────────
function MapFitter({ bounds }: { bounds: LatLngBounds }) {
  const map = useMap();
  useEffect(() => {
    map.fitBounds(bounds, { padding: [40, 40] });
  }, [map, bounds]);
  return null;
}

// ── Main component ───────────────────────────────────────────────────────────
const MapPage: React.FC = () => {
  const { team, role } = useAuth();
  const [locations, setLocations] = useState<Location[]>([]);
  const [armies, setArmies] = useState<Army[]>([]);
  const [loading, setLoading] = useState(true);
  const [selected, setSelected] = useState<Location | null>(null);
  const [showModal, setShowModal] = useState(false);
  const [filter, setFilter] = useState<string>('all');

  const isGamemaster = role === 'gamemaster';

  useEffect(() => {
    const load = async () => {
      try {
        const [locRes, armRes] = await Promise.all([
          locationAPI.getAll(),
          armyAPI.getAll(),
        ]);
        setLocations(locRes.data);
        setArmies(armRes.data);
      } catch {
        // Populate with example data for development
        setLocations([
          { id: 1, name: 'Berlin', type: 'city', hex_q: 0, hex_r: 0, team: 'blue', population: 5000, happiness: 75, resources: [] },
          { id: 2, name: 'Moscow', type: 'city', hex_q: 4, hex_r: -2, team: 'red', population: 6000, happiness: 70, resources: [] },
          { id: 3, name: 'Warsaw', type: 'town', hex_q: 2, hex_r: -1, team: null, population: 2000, happiness: 60, resources: [] },
          { id: 4, name: 'Prague', type: 'town', hex_q: 1, hex_r: 1, team: 'blue', population: 1800, happiness: 65, resources: [] },
          { id: 5, name: 'Vienna', type: 'village', hex_q: -1, hex_r: 2, team: 'blue', population: 800, happiness: 80, resources: [] },
          { id: 6, name: 'Kiev', type: 'town', hex_q: 3, hex_r: 0, team: 'red', population: 3000, happiness: 55, resources: [] },
          { id: 7, name: 'Minsk', type: 'base', hex_q: 3, hex_r: -2, team: 'red', population: 1500, happiness: 50, resources: [] },
          { id: 8, name: 'Hamburg', type: 'port', hex_q: -1, hex_r: -1, team: 'blue', population: 2200, happiness: 72, resources: [] },
          { id: 9, name: 'Rostock', type: 'airfield', hex_q: 0, hex_r: -2, team: 'blue', population: 500, happiness: 68, resources: [] },
          { id: 10, name: 'Odessa', type: 'port', hex_q: 4, hex_r: 1, team: 'red', population: 1900, happiness: 58, resources: [] },
        ]);
        setArmies([
          { id: 1, name: '1st Brigade', team: 'blue', general: 'Smith', location: 'Berlin', hex_q: 0, hex_r: 0, strength: 1200, morale: 85, units: [], status: 'idle' },
          { id: 2, name: 'Red Guard', team: 'red', general: 'Ivanov', location: 'Moscow', hex_q: 4, hex_r: -2, strength: 1500, morale: 80, units: [], status: 'idle' },
        ]);
      } finally {
        setLoading(false);
      }
    };
    load();
  }, []);

  const filteredLocations = useMemo(() => {
    if (filter === 'all') return locations;
    if (filter === 'mine') return locations.filter((l) => l.team === team);
    if (filter === 'enemy') return locations.filter((l) => l.team && l.team !== team);
    return locations.filter((l) => l.type === filter);
  }, [locations, filter, team]);

  // Compute SVG bounds
  const allCoords = useMemo(() => {
    const coords = locations.map((l) => hexToPixel(l.hex_q, l.hex_r));
    if (coords.length === 0) return { minX: -300, maxX: 300, minY: -300, maxY: 300 };
    const xs = coords.map((c) => c[0]);
    const ys = coords.map((c) => c[1]);
    return {
      minX: Math.min(...xs) - HEX_SIZE * 2,
      maxX: Math.max(...xs) + HEX_SIZE * 2,
      minY: Math.min(...ys) - HEX_SIZE * 2,
      maxY: Math.max(...ys) + HEX_SIZE * 2,
    };
  }, [locations]);

  const svgBounds = new LatLngBounds(
    [allCoords.minY, allCoords.minX],
    [allCoords.maxY, allCoords.maxX]
  );

  const handleHexClick = useCallback((loc: Location) => {
    setSelected(loc);
    setShowModal(true);
  }, []);

  if (loading) {
    return (
      <div className="d-flex justify-content-center align-items-center" style={{ minHeight: '60vh' }}>
        <Spinner animation="border" variant="primary" />
      </div>
    );
  }

  return (
    <Container fluid className="py-2" style={{ height: 'calc(100vh - 80px)' }}>
      <Row className="h-100 g-2">
        {/* Map */}
        <Col md={9} className="h-100">
          <Card className="h-100" style={{ background: '#0f0f23', border: '1px solid #ffffff10', overflow: 'hidden' }}>
            <Card.Header className="d-flex justify-content-between align-items-center py-2" style={{ background: '#16213e', borderBottom: '1px solid #ffffff10' }}>
              <span className="fw-bold text-light">Strategic Map</span>
              <Form.Select
                size="sm"
                value={filter}
                onChange={(e) => setFilter(e.target.value)}
                style={{ width: 160, background: '#1a1a2e', color: '#ccc', border: '1px solid #333' }}
              >
                <option value="all">All Locations</option>
                <option value="mine">My Territory</option>
                <option value="enemy">Enemy Territory</option>
                <option value="city">Cities</option>
                <option value="town">Towns</option>
                <option value="base">Bases</option>
              </Form.Select>
            </Card.Header>
            <Card.Body className="p-0" style={{ height: 'calc(100% - 50px)' }}>
              <MapContainer
                crs={CRS.Simple}
                bounds={svgBounds}
                maxZoom={4}
                minZoom={-2}
                zoom={0}
                style={{ height: '100%', width: '100%', background: '#0a0a14' }}
                attributionControl={false}
              >
                <MapFitter bounds={svgBounds} />
                <SVGOverlay bounds={svgBounds}>
                  <defs>
                    <pattern id="fogPattern" patternUnits="userSpaceOnUse" width="4" height="4">
                      <rect width="4" height="4" fill="#1a1a2e" />
                      <circle cx="2" cy="2" r="0.5" fill="#2a2a3e" />
                    </pattern>
                  </defs>

                  {/* Hex tiles for all locations */}
                  {filteredLocations.map((loc) => {
                    const [cx, cy] = hexToPixel(loc.hex_q, loc.hex_r);
                    const base = terrainColors[loc.type] || '#2d5a1e';
                    const fill = teamTint(base, loc.team);
                    const isFog = !isGamemaster && loc.team !== team && loc.team !== null;
                    const teamStroke = loc.team === 'blue' ? '#1e90ff' : loc.team === 'red' ? '#dc3545' : '#ffffff22';
                    const army = armies.find((a) => a.hex_q === loc.hex_q && a.hex_r === loc.hex_r);

                    return (
                      <g key={loc.id} onClick={() => handleHexClick(loc)} style={{ cursor: 'pointer' }}>
                        <polygon
                          points={hexCorners(cx, cy)}
                          fill={isFog ? '#1a1a2e' : fill}
                          stroke={teamStroke}
                          strokeWidth={1.5}
                          opacity={isFog ? 0.4 : 0.85}
                        />
                        {/* Location name */}
                        {!isFog && (
                          <text
                            x={cx}
                            y={cy - 8}
                            textAnchor="middle"
                            fontSize={10}
                            fill="#e0e0e0"
                            fontFamily="'Oswald', sans-serif"
                            style={{ pointerEvents: 'none', textShadow: '0 1px 3px rgba(0,0,0,0.9)' }}
                          >
                            {loc.name}
                          </text>
                        )}
                        {/* Location type icon */}
                        {!isFog && (
                          <text
                            x={cx}
                            y={cy + 12}
                            textAnchor="middle"
                            fontSize={8}
                            fill="#aaa"
                            style={{ pointerEvents: 'none' }}
                          >
                            {loc.type.toUpperCase()}
                          </text>
                        )}
                        {/* Fog overlay */}
                        {isFog && (
                          <>
                            <polygon points={hexCorners(cx, cy)} fill="url(#fogPattern)" opacity={0.6} style={{ pointerEvents: 'none' }} />
                            <text x={cx} y={cy} textAnchor="middle" fontSize={14} fill="#555" style={{ pointerEvents: 'none' }}>?</text>
                          </>
                        )}
                        {/* Army marker */}
                        {army && !isFog && (
                          <g>
                            <circle
                              cx={cx + HEX_SIZE * 0.3}
                              cy={cy - HEX_SIZE * 0.3}
                              r={8}
                              fill={army.team === 'blue' ? '#1e90ff' : '#dc3545'}
                              stroke="#fff"
                              strokeWidth={1}
                            />
                            <text
                              x={cx + HEX_SIZE * 0.3}
                              y={cy - HEX_SIZE * 0.3 + 3}
                              textAnchor="middle"
                              fontSize={7}
                              fill="#fff"
                              fontWeight="bold"
                              style={{ pointerEvents: 'none' }}
                            >
                              ⚔
                            </text>
                          </g>
                        )}
                      </g>
                    );
                  })}
                </SVGOverlay>
              </MapContainer>
            </Card.Body>
          </Card>
        </Col>

        {/* Legend / sidebar */}
        <Col md={3} className="h-100" style={{ overflowY: 'auto' }}>
          <Card className="mb-2" style={{ background: '#16213e', border: '1px solid #ffffff10' }}>
            <Card.Header style={{ background: '#0f0f23', color: '#e0e0e0', fontSize: '0.85rem' }} className="fw-bold border-0">
              Legend
            </Card.Header>
            <Card.Body style={{ fontSize: '0.75rem' }}>
              <div className="mb-2">
                <strong className="text-light">Team Colors:</strong>
                <div className="d-flex gap-2 mt-1">
                  <Badge style={{ background: '#1e90ff' }}>Blue Team</Badge>
                  <Badge style={{ background: '#dc3545' }}>Red Team</Badge>
                  <Badge bg="secondary">Neutral</Badge>
                </div>
              </div>
              <div className="mb-2">
                <strong className="text-light">Location Types:</strong>
                {Object.entries(terrainColors).map(([type, color]) => (
                  <div key={type} className="d-flex align-items-center gap-2 mt-1">
                    <span style={{ width: 12, height: 12, borderRadius: 2, background: color, display: 'inline-block' }} />
                    <span className="text-capitalize text-muted">{type}</span>
                  </div>
                ))}
              </div>
              <div>
                <strong className="text-light">Symbols:</strong>
                <div className="text-muted mt-1">⚔ = Army present</div>
                <div className="text-muted">? = Fog of war</div>
              </div>
            </Card.Body>
          </Card>

          {/* Selected location quick info */}
          {selected && (
            <Card style={{ background: '#16213e', border: `1px solid ${selected.team === 'blue' ? '#1e90ff' : selected.team === 'red' ? '#dc3545' : '#ffffff'}33` }}>
              <Card.Header style={{ background: '#0f0f23', color: '#e0e0e0' }} className="border-0 fw-bold">
                {selected.name}
              </Card.Header>
              <Card.Body style={{ fontSize: '0.8rem', color: '#ccc' }}>
                <div>Type: <span className="text-capitalize">{selected.type}</span></div>
                <div>Team: <Badge bg={selected.team === 'blue' ? 'primary' : selected.team === 'red' ? 'danger' : 'secondary'}>{selected.team || 'Neutral'}</Badge></div>
                <div>Population: {selected.population.toLocaleString()}</div>
                <div>Happiness: {selected.happiness}%</div>
                <div>Coords: [{selected.hex_q}, {selected.hex_r}]</div>
              </Card.Body>
            </Card>
          )}
        </Col>
      </Row>

      {/* Location detail modal */}
      <Modal show={showModal} onHide={() => setShowModal(false)} centered size="lg" contentClassName="bg-dark text-light">
        <Modal.Header closeButton closeVariant="white" style={{ background: '#16213e', borderBottom: '1px solid #ffffff10' }}>
          <Modal.Title>{selected?.name || 'Location Details'}</Modal.Title>
        </Modal.Header>
        <Modal.Body style={{ background: '#1a1a2e' }}>
          {selected && (
            <Row>
              <Col md={6}>
                <h6 className="text-muted">General Info</h6>
                <div className="mb-1">Type: <span className="text-capitalize fw-bold">{selected.type}</span></div>
                <div className="mb-1">
                  Team: <Badge bg={selected.team === 'blue' ? 'primary' : selected.team === 'red' ? 'danger' : 'secondary'}>
                    {selected.team || 'Neutral'}
                  </Badge>
                </div>
                <div className="mb-1">Population: {selected.population.toLocaleString()}</div>
                <div className="mb-1">Happiness: {selected.happiness}%</div>
                <div className="mb-1">Hex: [{selected.hex_q}, {selected.hex_r}]</div>
              </Col>
              <Col md={6}>
                <h6 className="text-muted">Resources</h6>
                {selected.resources.length === 0 ? (
                  <div className="text-muted">No resource data available</div>
                ) : (
                  selected.resources.map((r) => (
                    <div key={r.id} className="d-flex justify-content-between mb-1" style={{ fontSize: '0.8rem' }}>
                      <span>{r.name}</span>
                      <span>{r.quantity}/{r.max_capacity} {r.unit}</span>
                    </div>
                  ))
                )}
              </Col>
            </Row>
          )}
        </Modal.Body>
        <Modal.Footer style={{ background: '#16213e', borderTop: '1px solid #ffffff10' }}>
          <Button variant="outline-secondary" size="sm" onClick={() => setShowModal(false)}>Close</Button>
        </Modal.Footer>
      </Modal>
    </Container>
  );
};

export default MapPage;
