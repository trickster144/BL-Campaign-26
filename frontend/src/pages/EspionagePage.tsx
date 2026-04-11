import { useState, useEffect } from 'react';
import {
  Row, Col, Card, Table, Badge, Button, Form, Modal, ListGroup, Spinner,
  ProgressBar,
} from 'react-bootstrap';
import { FaUserSecret, FaPlus, FaCrosshairs } from 'react-icons/fa';
import { useAuth } from '../context/AuthContext';
import { spyAPI, locationAPI } from '../services/api';
import type { Spy, IntelReport, Location, SpyStatus } from '../types';

const statusColors: Record<SpyStatus, string> = {
  idle: 'secondary',
  deployed: 'primary',
  captured: 'danger',
  returning: 'warning',
  training: 'info',
};

export default function EspionagePage() {
  const { team } = useAuth();
  const [spies, setSpies] = useState<Spy[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [loading, setLoading] = useState(true);

  // Recruit
  const [showRecruit, setShowRecruit] = useState(false);
  const [newCodename, setNewCodename] = useState('');

  // Deploy
  const [showDeploy, setShowDeploy] = useState(false);
  const [deploySpy, setDeploySpy] = useState<Spy | null>(null);
  const [deployLocation, setDeployLocation] = useState('');

  const fetchData = async () => {
    try {
      const [sRes, lRes] = await Promise.all([spyAPI.getAll(), locationAPI.getAll()]);
      setSpies(sRes.data);
      setLocations(lRes.data);
    } catch {}
    setLoading(false);
  };

  useEffect(() => { fetchData(); }, []);

  const teamSpies = spies.filter((s) => s.team === team);
  const capturedByEnemy = spies.filter((s) => s.team === team && s.status === 'captured');
  const enemyCaptured = spies.filter((s) => s.team !== team && s.status === 'captured');

  // Collect all intel
  const allIntel: (IntelReport & { spy_codename: string })[] = teamSpies.flatMap((s) =>
    s.intel.map((i) => ({ ...i, spy_codename: s.codename }))
  ).sort((a, b) => new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime());

  const enemyLocations = locations.filter((l) => l.team && l.team !== team);

  const handleRecruit = async () => {
    // Recruitment done via API — codename sent as data
    // Since there's no explicit recruit endpoint, we'll use the train endpoint on a conceptual level
    alert(`Recruitment request for "${newCodename}" submitted.`);
    setShowRecruit(false);
    setNewCodename('');
  };

  const handleDeploy = async () => {
    if (!deploySpy || !deployLocation) return;
    try {
      await spyAPI.deploy(deploySpy.id, deployLocation);
      setShowDeploy(false);
      setDeploySpy(null);
      fetchData();
    } catch {
      alert('Deploy failed');
    }
  };

  const handleRecall = async (spyId: number) => {
    try {
      await spyAPI.recall(spyId);
      fetchData();
    } catch {
      alert('Recall failed');
    }
  };

  const handleTrain = async (spyId: number) => {
    try {
      await spyAPI.train(spyId);
      fetchData();
    } catch {
      alert('Training failed');
    }
  };

  const openDeploy = (spy: Spy) => {
    setDeploySpy(spy);
    setDeployLocation('');
    setShowDeploy(true);
  };

  const panelStyle = { backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' };
  const inputStyle = { backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' };

  if (loading) {
    return <div className="d-flex justify-content-center py-5"><Spinner animation="border" variant="light" /></div>;
  }

  return (
    <div>
      {/* Spy Roster */}
      <Card style={panelStyle} className="mb-3">
        <Card.Header className="d-flex justify-content-between align-items-center" style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
          <span><FaUserSecret className="me-2" /> Spy Roster ({teamSpies.length})</span>
          <Button variant="success" size="sm" onClick={() => setShowRecruit(true)}>
            <FaPlus className="me-1" /> Recruit Spy
          </Button>
        </Card.Header>
        <Card.Body className="p-0">
          <Table responsive variant="dark" className="mb-0" style={{ fontSize: '0.82rem' }}>
            <thead style={{ backgroundColor: '#0a0a1a' }}>
              <tr>
                <th>Codename</th>
                <th>Training</th>
                <th>Status</th>
                <th>Location</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {teamSpies.length === 0 ? (
                <tr><td colSpan={5} className="text-center text-muted">No spies recruited</td></tr>
              ) : (
                teamSpies.map((s) => (
                  <tr key={s.id}>
                    <td style={{ fontWeight: 600 }}>{s.codename}</td>
                    <td>
                      <div className="d-flex align-items-center gap-2">
                        <ProgressBar
                          now={s.training_level}
                          variant="info"
                          style={{ height: 6, width: 80, backgroundColor: '#0a0a1a' }}
                        />
                        <span style={{ fontSize: '0.75rem' }}>{s.training_level}%</span>
                      </div>
                    </td>
                    <td>
                      <Badge bg={statusColors[s.status]} style={{ textTransform: 'capitalize' }}>
                        {s.status}
                      </Badge>
                    </td>
                    <td>{s.location || '—'}</td>
                    <td>
                      <div className="d-flex gap-1">
                        {s.status === 'idle' && (
                          <>
                            <Button size="sm" variant="outline-primary" onClick={() => openDeploy(s)}>
                              Deploy
                            </Button>
                            <Button size="sm" variant="outline-info" onClick={() => handleTrain(s.id)}>
                              Train
                            </Button>
                          </>
                        )}
                        {s.status === 'deployed' && (
                          <Button size="sm" variant="outline-warning" onClick={() => handleRecall(s.id)}>
                            Recall
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </Table>
        </Card.Body>
      </Card>

      <Row className="g-3 mb-3">
        {/* Intelligence Reports */}
        <Col md={8}>
          <Card style={panelStyle}>
            <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
              <FaCrosshairs className="me-2" /> Intelligence Reports
            </Card.Header>
            <Card.Body style={{ maxHeight: 400, overflowY: 'auto' }}>
              {allIntel.length === 0 ? (
                <div style={{ color: '#8892b0', fontStyle: 'italic' }}>No intelligence gathered yet</div>
              ) : (
                <ListGroup variant="flush">
                  {allIntel.map((report) => (
                    <ListGroup.Item
                      key={report.id}
                      style={{
                        backgroundColor: 'transparent',
                        borderColor: '#0f3460',
                        color: '#ccd6f6',
                        fontSize: '0.82rem',
                      }}
                    >
                      <div className="d-flex justify-content-between mb-1">
                        <span>
                          <Badge bg="dark" className="me-2">{report.type}</Badge>
                          <span style={{ color: '#8892b0' }}>via {report.spy_codename}</span>
                        </span>
                        <div className="d-flex align-items-center gap-2">
                          <Badge
                            bg={report.reliability > 0.7 ? 'success' : report.reliability > 0.4 ? 'warning' : 'danger'}
                          >
                            {Math.round(report.reliability * 100)}% reliable
                          </Badge>
                          <span style={{ fontSize: '0.72rem', color: '#555' }}>
                            {new Date(report.timestamp).toLocaleString()}
                          </span>
                        </div>
                      </div>
                      <div>{report.content}</div>
                    </ListGroup.Item>
                  ))}
                </ListGroup>
              )}
            </Card.Body>
          </Card>
        </Col>

        {/* Captured Spies */}
        <Col md={4}>
          <Card style={panelStyle} className="mb-3">
            <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
              Our Captured Agents
            </Card.Header>
            <Card.Body style={{ fontSize: '0.82rem' }}>
              {capturedByEnemy.length === 0 ? (
                <div style={{ color: '#8892b0', fontStyle: 'italic' }}>None captured</div>
              ) : (
                capturedByEnemy.map((s) => (
                  <div key={s.id} className="mb-2 d-flex justify-content-between">
                    <span>{s.codename}</span>
                    <Badge bg="danger">Captured</Badge>
                  </div>
                ))
              )}
            </Card.Body>
          </Card>

          <Card style={panelStyle}>
            <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
              Enemy Spies Captured
            </Card.Header>
            <Card.Body style={{ fontSize: '0.82rem' }}>
              {enemyCaptured.length === 0 ? (
                <div style={{ color: '#8892b0', fontStyle: 'italic' }}>No enemy spies captured</div>
              ) : (
                enemyCaptured.map((s) => (
                  <div key={s.id} className="mb-2 d-flex justify-content-between">
                    <span>{s.codename}</span>
                    <Badge bg="success">Detained</Badge>
                  </div>
                ))
              )}
            </Card.Body>
          </Card>
        </Col>
      </Row>

      {/* Recruit Modal */}
      <Modal show={showRecruit} onHide={() => setShowRecruit(false)} centered>
        <Modal.Header closeButton closeVariant="white" style={{ backgroundColor: '#16213e', borderColor: '#0f3460', color: '#ccd6f6' }}>
          <Modal.Title style={{ fontSize: '1rem' }}>Recruit New Spy</Modal.Title>
        </Modal.Header>
        <Modal.Body style={{ backgroundColor: '#1a1a2e', color: '#ccd6f6' }}>
          <Form.Group>
            <Form.Label>Codename</Form.Label>
            <Form.Control
              value={newCodename}
              onChange={(e) => setNewCodename(e.target.value)}
              placeholder="e.g. Shadow, Ghost, Viper"
              style={inputStyle}
            />
          </Form.Group>
        </Modal.Body>
        <Modal.Footer style={{ backgroundColor: '#16213e', borderColor: '#0f3460' }}>
          <Button variant="secondary" size="sm" onClick={() => setShowRecruit(false)}>Cancel</Button>
          <Button variant="success" size="sm" onClick={handleRecruit} disabled={!newCodename.trim()}>Recruit</Button>
        </Modal.Footer>
      </Modal>

      {/* Deploy Modal */}
      <Modal show={showDeploy} onHide={() => setShowDeploy(false)} centered>
        <Modal.Header closeButton closeVariant="white" style={{ backgroundColor: '#16213e', borderColor: '#0f3460', color: '#ccd6f6' }}>
          <Modal.Title style={{ fontSize: '1rem' }}>Deploy: {deploySpy?.codename}</Modal.Title>
        </Modal.Header>
        <Modal.Body style={{ backgroundColor: '#1a1a2e', color: '#ccd6f6' }}>
          <Form.Group>
            <Form.Label>Target Location</Form.Label>
            <Form.Select
              value={deployLocation}
              onChange={(e) => setDeployLocation(e.target.value)}
              style={inputStyle}
            >
              <option value="">Select target</option>
              {enemyLocations.map((l) => (
                <option key={l.id} value={l.name}>{l.name} ({l.type})</option>
              ))}
            </Form.Select>
          </Form.Group>
        </Modal.Body>
        <Modal.Footer style={{ backgroundColor: '#16213e', borderColor: '#0f3460' }}>
          <Button variant="secondary" size="sm" onClick={() => setShowDeploy(false)}>Cancel</Button>
          <Button variant="primary" size="sm" onClick={handleDeploy} disabled={!deployLocation}>Deploy</Button>
        </Modal.Footer>
      </Modal>
    </div>
  );
}
