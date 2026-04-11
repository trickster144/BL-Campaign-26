import { useState, useEffect } from 'react';
import {
  Row, Col, Card, Table, Badge, Button, Modal, Form, Spinner, ProgressBar,
} from 'react-bootstrap';
import { FaPlus, FaArrowRight } from 'react-icons/fa';
import { useAuth } from '../context/AuthContext';
import { useGame } from '../context/GameContext';
import { armyAPI, battleAPI, locationAPI } from '../services/api';
import ArmyCard from '../components/game/ArmyCard';
import type { Army, Battle, Location } from '../types';

export default function MilitaryPage() {
  const { team } = useAuth();
  const { gameState } = useGame();
  const [armies, setArmies] = useState<Army[]>([]);
  const [battles, setBattles] = useState<Battle[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [loading, setLoading] = useState(true);

  // Modals
  const [showCreate, setShowCreate] = useState(false);
  const [showMove, setShowMove] = useState(false);
  const [newName, setNewName] = useState('');
  const [newLocation, setNewLocation] = useState('');
  const [moveArmy, setMoveArmy] = useState<Army | null>(null);
  const [moveQ, setMoveQ] = useState(0);
  const [moveR, setMoveR] = useState(0);

  const fetchData = async () => {
    try {
      const [aRes, bRes, lRes] = await Promise.all([
        armyAPI.getAll(),
        battleAPI.getAll(),
        locationAPI.getAll(),
      ]);
      setArmies(aRes.data);
      setBattles(bRes.data);
      setLocations(lRes.data.filter((l) => l.team === team));
    } catch {}
    setLoading(false);
  };

  useEffect(() => { fetchData(); }, [gameState?.current_tick]);

  const teamArmies = armies.filter((a) => a.team === team);
  const activeBattles = battles.filter((b) => b.status === 'in_progress');
  const pastBattles = battles.filter((b) => b.status === 'completed').slice(0, 10);

  // Hospital: count armies in combat or retreating as "patients"
  const patients = armies.filter((a) => a.team === team && (a.status === 'in_combat' || a.status === 'retreating'));

  const handleCreate = async () => {
    if (!newName.trim()) return;
    try {
      await armyAPI.create({ name: newName, team, location: newLocation });
      setShowCreate(false);
      setNewName('');
      fetchData();
    } catch {
      alert('Failed to create army');
    }
  };

  const handleMove = async () => {
    if (!moveArmy) return;
    try {
      await armyAPI.move(moveArmy.id, moveQ, moveR);
      setShowMove(false);
      setMoveArmy(null);
      fetchData();
    } catch {
      alert('Failed to move army');
    }
  };

  const openMove = (army: Army) => {
    setMoveArmy(army);
    setMoveQ(army.hex_q);
    setMoveR(army.hex_r);
    setShowMove(true);
  };

  const panelStyle = { backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' };

  if (loading) {
    return <div className="d-flex justify-content-center py-5"><Spinner animation="border" variant="light" /></div>;
  }

  return (
    <div>
      {/* Army List */}
      <Card style={panelStyle} className="mb-3">
        <Card.Header className="d-flex justify-content-between align-items-center" style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
          <span>Your Armies ({teamArmies.length})</span>
          <Button variant="success" size="sm" onClick={() => setShowCreate(true)}>
            <FaPlus className="me-1" /> Create Army
          </Button>
        </Card.Header>
        <Card.Body>
          {teamArmies.length === 0 ? (
            <div style={{ color: '#8892b0', fontStyle: 'italic' }}>No armies. Create one to get started.</div>
          ) : (
            <Row className="g-3">
              {teamArmies.map((a) => (
                <Col key={a.id} md={6} lg={4}>
                  <ArmyCard army={a} onClick={() => openMove(a)} />
                </Col>
              ))}
            </Row>
          )}
        </Card.Body>
      </Card>

      <Row className="g-3 mb-3">
        {/* Active Battles */}
        <Col md={6}>
          <Card style={panelStyle}>
            <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
              Active Battles ({activeBattles.length})
            </Card.Header>
            <Card.Body>
              {activeBattles.length === 0 ? (
                <div style={{ color: '#8892b0', fontStyle: 'italic' }}>No active battles</div>
              ) : (
                activeBattles.map((b) => (
                  <Card key={b.id} className="mb-2" style={{ backgroundColor: '#0d1117', border: '1px solid #dc354544', color: '#ccd6f6' }}>
                    <Card.Body className="p-3" style={{ fontSize: '0.85rem' }}>
                      <div className="d-flex justify-content-between mb-1">
                        <span>
                          <span style={{ color: '#1e90ff' }}>{b.attacker}</span>
                          {' vs '}
                          <span style={{ color: '#dc3545' }}>{b.defender}</span>
                        </span>
                        <Badge bg="danger" style={{ animation: 'pulse-badge 1.2s infinite' }}>
                          IN COMBAT
                        </Badge>
                      </div>
                      <div style={{ color: '#8892b0' }}>
                        📍 {b.location} ({b.hex_q}, {b.hex_r})
                      </div>
                      <div className="mt-1" style={{ fontSize: '0.78rem' }}>
                        Att. losses: {b.casualties.attacker_losses} | Def. losses: {b.casualties.defender_losses}
                      </div>
                    </Card.Body>
                  </Card>
                ))
              )}
            </Card.Body>
          </Card>
        </Col>

        {/* Hospital */}
        <Col md={6}>
          <Card style={panelStyle}>
            <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
              Hospital Status
            </Card.Header>
            <Card.Body>
              <div className="d-flex justify-content-between mb-2" style={{ fontSize: '0.85rem' }}>
                <span>Active Patients (armies in combat/retreating)</span>
                <Badge bg={patients.length > 0 ? 'warning' : 'success'}>{patients.length}</Badge>
              </div>
              <ProgressBar
                now={patients.length}
                max={Math.max(teamArmies.length, 1)}
                variant={patients.length > 0 ? 'warning' : 'success'}
                style={{ height: 8, backgroundColor: '#0a0a1a' }}
              />
              {patients.map((a) => (
                <div key={a.id} className="mt-2 d-flex justify-content-between" style={{ fontSize: '0.8rem' }}>
                  <span>{a.name}</span>
                  <Badge bg="warning" style={{ textTransform: 'capitalize' }}>{a.status.replace('_', ' ')}</Badge>
                </div>
              ))}
            </Card.Body>
          </Card>
        </Col>
      </Row>

      {/* Battle History */}
      <Card style={panelStyle}>
        <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
          Battle History
        </Card.Header>
        <Card.Body className="p-0">
          <Table responsive variant="dark" className="mb-0" style={{ fontSize: '0.82rem' }}>
            <thead style={{ backgroundColor: '#0a0a1a' }}>
              <tr>
                <th>Location</th>
                <th>Attacker</th>
                <th>Defender</th>
                <th>Att. Losses</th>
                <th>Def. Losses</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {pastBattles.length === 0 ? (
                <tr><td colSpan={6} className="text-center text-muted">No battle history</td></tr>
              ) : (
                pastBattles.map((b) => (
                  <tr key={b.id}>
                    <td>{b.location}</td>
                    <td style={{ color: b.attacker_team === 'blue' ? '#1e90ff' : '#dc3545' }}>{b.attacker}</td>
                    <td style={{ color: b.defender_team === 'blue' ? '#1e90ff' : '#dc3545' }}>{b.defender}</td>
                    <td>{b.casualties.attacker_losses}</td>
                    <td>{b.casualties.defender_losses}</td>
                    <td><Badge bg="secondary" style={{ textTransform: 'capitalize' }}>{b.status}</Badge></td>
                  </tr>
                ))
              )}
            </tbody>
          </Table>
        </Card.Body>
      </Card>

      {/* Create Army Modal */}
      <Modal show={showCreate} onHide={() => setShowCreate(false)} centered>
        <Modal.Header closeButton closeVariant="white" style={{ backgroundColor: '#16213e', borderColor: '#0f3460', color: '#ccd6f6' }}>
          <Modal.Title style={{ fontSize: '1rem' }}>Create Army</Modal.Title>
        </Modal.Header>
        <Modal.Body style={{ backgroundColor: '#1a1a2e', color: '#ccd6f6' }}>
          <Form.Group className="mb-3">
            <Form.Label>Army Name</Form.Label>
            <Form.Control
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
              placeholder="e.g. 1st Battalion"
              style={{ backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' }}
            />
          </Form.Group>
          <Form.Group>
            <Form.Label>Starting Location</Form.Label>
            <Form.Select
              value={newLocation}
              onChange={(e) => setNewLocation(e.target.value)}
              style={{ backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' }}
            >
              <option value="">Select location</option>
              {locations.map((l) => (
                <option key={l.id} value={l.name}>{l.name}</option>
              ))}
            </Form.Select>
          </Form.Group>
        </Modal.Body>
        <Modal.Footer style={{ backgroundColor: '#16213e', borderColor: '#0f3460' }}>
          <Button variant="secondary" size="sm" onClick={() => setShowCreate(false)}>Cancel</Button>
          <Button variant="success" size="sm" onClick={handleCreate} disabled={!newName.trim()}>Create</Button>
        </Modal.Footer>
      </Modal>

      {/* Move Army Modal */}
      <Modal show={showMove} onHide={() => setShowMove(false)} centered>
        <Modal.Header closeButton closeVariant="white" style={{ backgroundColor: '#16213e', borderColor: '#0f3460', color: '#ccd6f6' }}>
          <Modal.Title style={{ fontSize: '1rem' }}>
            <FaArrowRight className="me-2" />
            Move: {moveArmy?.name}
          </Modal.Title>
        </Modal.Header>
        <Modal.Body style={{ backgroundColor: '#1a1a2e', color: '#ccd6f6' }}>
          <Row className="g-3">
            <Col>
              <Form.Group>
                <Form.Label>Hex Q</Form.Label>
                <Form.Control
                  type="number"
                  value={moveQ}
                  onChange={(e) => setMoveQ(Number(e.target.value))}
                  style={{ backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' }}
                />
              </Form.Group>
            </Col>
            <Col>
              <Form.Group>
                <Form.Label>Hex R</Form.Label>
                <Form.Control
                  type="number"
                  value={moveR}
                  onChange={(e) => setMoveR(Number(e.target.value))}
                  style={{ backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' }}
                />
              </Form.Group>
            </Col>
          </Row>
        </Modal.Body>
        <Modal.Footer style={{ backgroundColor: '#16213e', borderColor: '#0f3460' }}>
          <Button variant="secondary" size="sm" onClick={() => setShowMove(false)}>Cancel</Button>
          <Button variant="primary" size="sm" onClick={handleMove}>Move</Button>
        </Modal.Footer>
      </Modal>

      <style>{`
        @keyframes pulse-badge { 0%,100%{opacity:1} 50%{opacity:0.5} }
      `}</style>
    </div>
  );
}
