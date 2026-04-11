import { useState, useEffect } from 'react';
import {
  Row, Col, Card, Table, Badge, Button, Form, ProgressBar, Spinner,
} from 'react-bootstrap';
import { FaSeedling, FaTractor, FaLeaf } from 'react-icons/fa';
import { useAuth } from '../context/AuthContext';
import { useGame } from '../context/GameContext';
import { farmAPI, locationAPI } from '../services/api';
import type { Field, Livestock, Location, CropType } from '../types';

const CROP_OPTIONS: CropType[] = ['wheat', 'corn', 'potatoes', 'vegetables', 'cotton', 'tobacco'];

const cropColors: Record<CropType, string> = {
  wheat: '#f0c040',
  corn: '#e6b800',
  potatoes: '#c8a060',
  vegetables: '#4caf50',
  cotton: '#e0e0e0',
  tobacco: '#8d6e63',
};

export default function FarmPage() {
  const { team } = useAuth();
  const { weather } = useGame();
  const [fields, setFields] = useState<Field[]>([]);
  const [livestock, setLivestock] = useState<Livestock[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [loading, setLoading] = useState(true);

  // New field form
  const [selectedLoc, setSelectedLoc] = useState<number | ''>('');
  const [plantCrop, setPlantCrop] = useState<CropType>('wheat');

  const fetchData = async () => {
    try {
      const [fRes, lsRes, locRes] = await Promise.all([
        farmAPI.getFields(),
        farmAPI.getLivestock(),
        locationAPI.getAll(),
      ]);
      setFields(fRes.data);
      setLivestock(lsRes.data);
      setLocations(locRes.data.filter((l) => l.team === team));
    } catch {}
    setLoading(false);
  };

  useEffect(() => { fetchData(); }, []);

  const handleCreateField = async () => {
    if (!selectedLoc) return;
    try {
      await farmAPI.createField({ location_id: selectedLoc as number });
      fetchData();
    } catch {
      alert('Failed to create field');
    }
  };

  const handlePlant = async (fieldId: number) => {
    try {
      await farmAPI.plant(fieldId, plantCrop);
      fetchData();
    } catch {
      alert('Failed to plant');
    }
  };

  const handleFertilize = async (fieldId: number) => {
    try {
      await farmAPI.fertilize(fieldId);
      fetchData();
    } catch {
      alert('Failed to fertilize');
    }
  };

  const handleHarvest = async (fieldId: number) => {
    try {
      await farmAPI.harvest(fieldId);
      fetchData();
    } catch {
      alert('Failed to harvest');
    }
  };

  const panelStyle = { backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' };
  const inputStyle = { backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' };

  if (loading) {
    return <div className="d-flex justify-content-center py-5"><Spinner animation="border" variant="light" /></div>;
  }

  return (
    <div>
      {/* Create Field & Weather Effects */}
      <Row className="g-3 mb-3">
        <Col md={6}>
          <Card style={panelStyle}>
            <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
              <FaTractor className="me-2" /> Create New Field
            </Card.Header>
            <Card.Body>
              <Row className="g-2">
                <Col>
                  <Form.Select
                    value={selectedLoc}
                    onChange={(e) => setSelectedLoc(e.target.value ? Number(e.target.value) : '')}
                    style={inputStyle}
                  >
                    <option value="">Select location</option>
                    {locations.map((l) => (
                      <option key={l.id} value={l.id}>{l.name}</option>
                    ))}
                  </Form.Select>
                </Col>
                <Col xs="auto">
                  <Button variant="success" onClick={handleCreateField} disabled={!selectedLoc}>
                    Create
                  </Button>
                </Col>
              </Row>
            </Card.Body>
          </Card>
        </Col>

        <Col md={6}>
          <Card style={panelStyle}>
            <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
              <FaLeaf className="me-2" /> Weather Effects on Crops
            </Card.Header>
            <Card.Body style={{ fontSize: '0.85rem' }}>
              {weather ? (
                <>
                  <div className="d-flex justify-content-between mb-2">
                    <span>Weather</span>
                    <span style={{ textTransform: 'capitalize' }}>{weather.type} — {weather.temperature}°C</span>
                  </div>
                  <div className="d-flex justify-content-between mb-2">
                    <span>Crop Modifier</span>
                    <Badge bg={weather.effects.crop_modifier >= 1 ? 'success' : 'danger'}>
                      {weather.effects.crop_modifier >= 1 ? '+' : ''}
                      {Math.round((weather.effects.crop_modifier - 1) * 100)}%
                    </Badge>
                  </div>
                  <div className="d-flex justify-content-between mb-2">
                    <span>Season</span>
                    <span style={{ textTransform: 'capitalize' }}>{weather.season}</span>
                  </div>
                  <div style={{ color: '#8892b0', fontSize: '0.78rem' }}>
                    {weather.effects.crop_modifier >= 1
                      ? '🌱 Good growing conditions'
                      : '⚠️ Harsh conditions — crop yields reduced'}
                  </div>
                </>
              ) : (
                <div style={{ color: '#8892b0', fontStyle: 'italic' }}>Weather data unavailable</div>
              )}
            </Card.Body>
          </Card>
        </Col>
      </Row>

      {/* Crop selector for planting */}
      <div className="mb-3 d-flex align-items-center gap-2">
        <span style={{ fontSize: '0.85rem' }}>Plant crop type:</span>
        <Form.Select
          value={plantCrop}
          onChange={(e) => setPlantCrop(e.target.value as CropType)}
          style={{ ...inputStyle, width: 180 }}
          size="sm"
        >
          {CROP_OPTIONS.map((c) => (
            <option key={c} value={c} style={{ textTransform: 'capitalize' }}>{c}</option>
          ))}
        </Form.Select>
      </div>

      {/* Fields */}
      <Card style={panelStyle} className="mb-3">
        <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
          <FaSeedling className="me-2" /> Fields ({fields.length})
        </Card.Header>
        <Card.Body>
          {fields.length === 0 ? (
            <div style={{ color: '#8892b0', fontStyle: 'italic' }}>No fields. Create one above.</div>
          ) : (
            <Row className="g-3">
              {fields.map((f) => (
                <Col key={f.id} md={6} lg={4}>
                  <Card style={{ backgroundColor: '#0d1117', border: '1px solid #0f3460', color: '#ccd6f6' }}>
                    <Card.Body className="p-3">
                      <div className="d-flex justify-content-between mb-2">
                        <span style={{ fontWeight: 600 }}>
                          {f.crop_type ? (
                            <span style={{ color: cropColors[f.crop_type] || '#ccc', textTransform: 'capitalize' }}>
                              {f.crop_type}
                            </span>
                          ) : (
                            <span style={{ color: '#555' }}>Empty Field</span>
                          )}
                        </span>
                        <span style={{ fontSize: '0.75rem', color: '#8892b0' }}>📍 {f.location}</span>
                      </div>

                      {/* Growth Progress */}
                      <div className="mb-2">
                        <div className="d-flex justify-content-between" style={{ fontSize: '0.75rem' }}>
                          <span style={{ color: '#8892b0' }}>Growth</span>
                          <span>{Math.round(f.growth_progress)}%</span>
                        </div>
                        <ProgressBar
                          now={f.growth_progress}
                          variant={f.growth_progress >= 100 ? 'success' : f.growth_progress > 50 ? 'info' : 'warning'}
                          style={{ height: 6, backgroundColor: '#0a0a1a' }}
                        />
                      </div>

                      {/* Health */}
                      <div className="mb-2">
                        <div className="d-flex justify-content-between" style={{ fontSize: '0.75rem' }}>
                          <span style={{ color: '#8892b0' }}>Health</span>
                          <span>{f.health}%</span>
                        </div>
                        <ProgressBar
                          now={f.health}
                          variant={f.health > 60 ? 'success' : f.health > 30 ? 'warning' : 'danger'}
                          style={{ height: 4, backgroundColor: '#0a0a1a' }}
                        />
                      </div>

                      <div className="d-flex justify-content-between mb-2" style={{ fontSize: '0.78rem', color: '#8892b0' }}>
                        <span>Fertilized: {f.is_fertilized ? '✅' : '❌'}</span>
                        <span>Est. yield: {f.yield_estimate}</span>
                      </div>

                      {/* Actions */}
                      <div className="d-flex gap-1">
                        {!f.is_planted && (
                          <Button size="sm" variant="outline-success" onClick={() => handlePlant(f.id)} className="flex-fill">
                            Plant
                          </Button>
                        )}
                        {f.is_planted && !f.is_fertilized && (
                          <Button size="sm" variant="outline-warning" onClick={() => handleFertilize(f.id)} className="flex-fill">
                            Fertilize
                          </Button>
                        )}
                        {f.is_planted && f.growth_progress >= 100 && (
                          <Button size="sm" variant="outline-info" onClick={() => handleHarvest(f.id)} className="flex-fill">
                            Harvest
                          </Button>
                        )}
                      </div>
                    </Card.Body>
                  </Card>
                </Col>
              ))}
            </Row>
          )}
        </Card.Body>
      </Card>

      {/* Livestock */}
      <Card style={panelStyle}>
        <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
          🐄 Livestock Overview
        </Card.Header>
        <Card.Body className="p-0">
          <Table responsive variant="dark" className="mb-0" style={{ fontSize: '0.82rem' }}>
            <thead style={{ backgroundColor: '#0a0a1a' }}>
              <tr>
                <th>Type</th>
                <th>Count</th>
                <th>Health</th>
              </tr>
            </thead>
            <tbody>
              {livestock.length === 0 ? (
                <tr><td colSpan={3} className="text-center text-muted">No livestock</td></tr>
              ) : (
                livestock.map((ls) => (
                  <tr key={ls.id}>
                    <td style={{ textTransform: 'capitalize' }}>{ls.type}</td>
                    <td>{ls.count.toLocaleString()}</td>
                    <td>
                      <ProgressBar
                        now={ls.health}
                        variant={ls.health > 60 ? 'success' : ls.health > 30 ? 'warning' : 'danger'}
                        style={{ height: 6, backgroundColor: '#0a0a1a' }}
                      />
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </Table>
        </Card.Body>
      </Card>
    </div>
  );
}
