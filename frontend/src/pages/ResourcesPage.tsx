import { useState, useEffect, useCallback } from 'react';
import {
  Row, Col, Card, Table, Badge, ProgressBar, Form, Button, Modal, Spinner,
} from 'react-bootstrap';
import { useAuth } from '../context/AuthContext';
import { locationAPI, resourceAPI, buildingAPI } from '../services/api';
import type { Location, Resource, Building, BuildingType, ResourceCategory } from '../types';

const STORAGE_CATEGORIES: { key: ResourceCategory; label: string; color: string }[] = [
  { key: 'raw', label: 'Raw Materials', color: '#78909c' },
  { key: 'processed', label: 'Processed Goods', color: '#64ffda' },
  { key: 'food', label: 'Food', color: '#66bb6a' },
  { key: 'fuel', label: 'Fuel', color: '#ffa726' },
  { key: 'military', label: 'Military', color: '#ef5350' },
  { key: 'medical', label: 'Medical', color: '#42a5f5' },
];

const BUILDING_OPTIONS: { type: BuildingType; label: string }[] = [
  { type: 'factory', label: 'Factory' },
  { type: 'refinery', label: 'Refinery' },
  { type: 'warehouse', label: 'Warehouse' },
  { type: 'farm', label: 'Farm' },
  { type: 'barracks', label: 'Barracks' },
  { type: 'hospital', label: 'Hospital' },
];

export default function ResourcesPage() {
  const { team } = useAuth();
  const [locations, setLocations] = useState<Location[]>([]);
  const [selectedLoc, setSelectedLoc] = useState<number | null>(null);
  const [resources, setResources] = useState<Resource[]>([]);
  const [buildings, setBuildings] = useState<Building[]>([]);
  const [loading, setLoading] = useState(true);
  const [showBuildModal, setShowBuildModal] = useState(false);
  const [buildType, setBuildType] = useState<BuildingType>('factory');

  useEffect(() => {
    locationAPI
      .getAll()
      .then((r) => {
        const teamLocs = r.data.filter((l) => l.team === team);
        setLocations(teamLocs);
        if (teamLocs.length > 0 && selectedLoc === null) {
          setSelectedLoc(teamLocs[0].id);
        }
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [team]);

  const fetchLocationData = useCallback(async (locId: number) => {
    try {
      const [resData, bldData] = await Promise.all([
        locationAPI.getResources(locId),
        locationAPI.getBuildings(locId),
      ]);
      setResources(resData.data);
      setBuildings(bldData.data);
    } catch {
      setResources([]);
      setBuildings([]);
    }
  }, []);

  useEffect(() => {
    if (selectedLoc) fetchLocationData(selectedLoc);
  }, [selectedLoc, fetchLocationData]);

  const handleBuild = async () => {
    if (!selectedLoc) return;
    try {
      await buildingAPI.build({ type: buildType, location_id: selectedLoc });
      setShowBuildModal(false);
      fetchLocationData(selectedLoc);
    } catch {
      alert('Failed to build');
    }
  };

  const panelStyle = {
    backgroundColor: '#16213e',
    border: '1px solid #0f3460',
    color: '#ccd6f6',
  };

  const resourceStatus = (r: Resource) => {
    if (r.max_capacity <= 0) return { label: 'N/A', bg: 'secondary' };
    const pct = r.quantity / r.max_capacity;
    if (pct < 0.1) return { label: 'Critical', bg: 'danger' };
    if (pct < 0.25) return { label: 'Low', bg: 'warning' };
    if (pct > 0.9) return { label: 'Full', bg: 'info' };
    return { label: 'OK', bg: 'success' };
  };

  if (loading) {
    return <div className="d-flex justify-content-center py-5"><Spinner animation="border" variant="light" /></div>;
  }

  return (
    <div>
      {/* Location selector */}
      <Row className="mb-3">
        <Col md={4}>
          <Form.Select
            value={selectedLoc ?? ''}
            onChange={(e) => setSelectedLoc(Number(e.target.value))}
            style={{ backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' }}
          >
            {locations.map((l) => (
              <option key={l.id} value={l.id}>
                {l.name} ({l.type})
              </option>
            ))}
          </Form.Select>
        </Col>
        <Col className="d-flex align-items-center">
          <Button variant="outline-success" size="sm" onClick={() => setShowBuildModal(true)}>
            + Build Factory
          </Button>
        </Col>
      </Row>

      {/* Storage overview by category */}
      <Row className="g-3 mb-3">
        {STORAGE_CATEGORIES.map((cat) => {
          const catResources = resources.filter((r) => r.category === cat.key);
          const totalQty = catResources.reduce((s, r) => s + r.quantity, 0);
          const totalCap = catResources.reduce((s, r) => s + r.max_capacity, 0);
          return (
            <Col key={cat.key} md={4} lg={2}>
              <Card style={panelStyle}>
                <Card.Body className="p-2 text-center">
                  <div style={{ fontSize: '0.75rem', color: '#8892b0', textTransform: 'uppercase' }}>
                    {cat.label}
                  </div>
                  <div style={{ fontSize: '1.2rem', fontWeight: 700, color: cat.color }}>
                    {totalQty.toLocaleString()}
                  </div>
                  {totalCap > 0 && (
                    <ProgressBar
                      now={(totalQty / totalCap) * 100}
                      variant={totalQty / totalCap > 0.5 ? 'success' : totalQty / totalCap > 0.25 ? 'warning' : 'danger'}
                      style={{ height: 4, backgroundColor: '#0a0a1a', marginTop: 4 }}
                    />
                  )}
                </Card.Body>
              </Card>
            </Col>
          );
        })}
      </Row>

      {/* Full resource table */}
      <Card style={panelStyle} className="mb-3">
        <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
          Resource Inventory
        </Card.Header>
        <Card.Body className="p-0">
          <Table responsive hover variant="dark" className="mb-0" style={{ fontSize: '0.82rem' }}>
            <thead style={{ backgroundColor: '#0a0a1a' }}>
              <tr>
                <th>Name</th>
                <th>Category</th>
                <th>Quantity</th>
                <th>Capacity</th>
                <th>Unit</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {resources.length === 0 ? (
                <tr>
                  <td colSpan={6} className="text-center text-muted py-3">No resources found</td>
                </tr>
              ) : (
                resources.map((r) => {
                  const st = resourceStatus(r);
                  return (
                    <tr key={r.id}>
                      <td>{r.name}</td>
                      <td><Badge bg="dark" style={{ textTransform: 'capitalize' }}>{r.category}</Badge></td>
                      <td>{r.quantity.toLocaleString()}</td>
                      <td>{r.max_capacity.toLocaleString()}</td>
                      <td>{r.unit}</td>
                      <td><Badge bg={st.bg}>{st.label}</Badge></td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </Table>
        </Card.Body>
      </Card>

      {/* Production chains */}
      <Card style={panelStyle} className="mb-3">
        <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
          Production Chains
        </Card.Header>
        <Card.Body>
          {buildings.filter((b) => b.production).length === 0 ? (
            <div style={{ color: '#8892b0', fontStyle: 'italic', fontSize: '0.85rem' }}>
              No active production chains
            </div>
          ) : (
            <Row className="g-3">
              {buildings
                .filter((b) => b.production)
                .map((b) => (
                  <Col key={b.id} md={6} lg={4}>
                    <Card style={{ backgroundColor: '#0d1117', border: '1px solid #0f3460', color: '#ccd6f6' }}>
                      <Card.Body className="p-3">
                        <div className="d-flex justify-content-between mb-2">
                          <span style={{ fontWeight: 600 }}>{b.name}</span>
                          <Badge bg={b.is_operational ? 'success' : 'secondary'}>
                            {b.is_operational ? 'Active' : 'Offline'}
                          </Badge>
                        </div>
                        <div style={{ fontSize: '0.8rem' }}>
                          <div style={{ color: '#8892b0' }}>Inputs:</div>
                          {b.production!.inputs.map((inp) => (
                            <div key={inp.id} className="ms-2">
                              {inp.name}: {inp.quantity} {inp.unit}
                            </div>
                          ))}
                          <div className="text-center my-1" style={{ color: '#64ffda' }}>→</div>
                          <div style={{ color: '#8892b0' }}>Outputs:</div>
                          {b.production!.outputs.map((out) => (
                            <div key={out.id} className="ms-2">
                              {out.name}: {out.quantity} {out.unit}
                            </div>
                          ))}
                          <div style={{ color: '#555', marginTop: 4 }}>
                            Cycle: {b.production!.cycle_time} ticks
                          </div>
                        </div>
                      </Card.Body>
                    </Card>
                  </Col>
                ))}
            </Row>
          )}
        </Card.Body>
      </Card>

      {/* Build Modal */}
      <Modal show={showBuildModal} onHide={() => setShowBuildModal(false)} centered>
        <Modal.Header
          closeButton
          closeVariant="white"
          style={{ backgroundColor: '#16213e', borderColor: '#0f3460', color: '#ccd6f6' }}
        >
          <Modal.Title style={{ fontSize: '1rem' }}>Build New Structure</Modal.Title>
        </Modal.Header>
        <Modal.Body style={{ backgroundColor: '#1a1a2e', color: '#ccd6f6' }}>
          <Form.Group className="mb-3">
            <Form.Label>Building Type</Form.Label>
            <Form.Select
              value={buildType}
              onChange={(e) => setBuildType(e.target.value as BuildingType)}
              style={{ backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' }}
            >
              {BUILDING_OPTIONS.map((o) => (
                <option key={o.type} value={o.type}>{o.label}</option>
              ))}
            </Form.Select>
          </Form.Group>
        </Modal.Body>
        <Modal.Footer style={{ backgroundColor: '#16213e', borderColor: '#0f3460' }}>
          <Button variant="secondary" size="sm" onClick={() => setShowBuildModal(false)}>
            Cancel
          </Button>
          <Button variant="success" size="sm" onClick={handleBuild}>
            Build
          </Button>
        </Modal.Footer>
      </Modal>
    </div>
  );
}
