import { useState, useEffect } from 'react';
import {
  Row, Col, Card, Table, Badge, Button, Form, Spinner, ListGroup,
} from 'react-bootstrap';
import { FaUsersCog, FaPlay, FaPause, FaUndo, FaHistory } from 'react-icons/fa';
import { useAuth } from '../context/AuthContext';
import { adminAPI, gameAPI } from '../services/api';
import type { User, TeamApplication, AuditLogEntry, UserRole } from '../types';

const ROLES: UserRole[] = ['member', 'officer', 'admin', 'gamemaster', 'observer'];

export default function AdminPage() {
  const { role } = useAuth();
  const isGamemaster = role === 'gamemaster';

  const [users, setUsers] = useState<User[]>([]);
  const [applications, setApplications] = useState<TeamApplication[]>([]);
  const [auditLog, setAuditLog] = useState<AuditLogEntry[]>([]);
  const [loading, setLoading] = useState(true);

  // Gamemaster controls
  const [tickOverride, setTickOverride] = useState('');

  const fetchData = async () => {
    try {
      const [uRes, aRes, logRes] = await Promise.all([
        adminAPI.getUsers(),
        adminAPI.getApplications(),
        adminAPI.getAuditLog(),
      ]);
      setUsers(uRes.data);
      setApplications(aRes.data);
      setAuditLog(logRes.data);
    } catch {}
    setLoading(false);
  };

  useEffect(() => { fetchData(); }, []);

  const handleRoleChange = async (userId: number, newRole: string) => {
    try {
      await adminAPI.updateUserRole(userId, newRole);
      fetchData();
    } catch {
      alert('Failed to update role');
    }
  };

  const handleApplication = async (id: number, action: 'approve' | 'reject') => {
    try {
      await adminAPI.processApplication(id, action);
      fetchData();
    } catch {
      alert('Failed to process application');
    }
  };

  const handleStartGame = async () => {
    try { await gameAPI.startGame(); alert('Game started'); } catch { alert('Failed'); }
  };
  const handlePauseGame = async () => {
    try { await gameAPI.pauseGame(); alert('Game paused'); } catch { alert('Failed'); }
  };
  const handleResetGame = async () => {
    if (!confirm('Are you sure you want to reset the game?')) return;
    try { await gameAPI.resetGame(); alert('Game reset'); } catch { alert('Failed'); }
  };

  const panelStyle = { backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' };
  const inputStyle = { backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' };

  if (loading) {
    return <div className="d-flex justify-content-center py-5"><Spinner animation="border" variant="light" /></div>;
  }

  return (
    <div>
      {/* User Management */}
      <Card style={panelStyle} className="mb-3">
        <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
          <FaUsersCog className="me-2" /> User Management
        </Card.Header>
        <Card.Body className="p-0">
          <Table responsive variant="dark" className="mb-0" style={{ fontSize: '0.82rem' }}>
            <thead style={{ backgroundColor: '#0a0a1a' }}>
              <tr>
                <th>Username</th>
                <th>Steam ID</th>
                <th>Team</th>
                <th>Role</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id}>
                  <td>
                    <div className="d-flex align-items-center gap-2">
                      {u.avatar_url && (
                        <img src={u.avatar_url} alt="" style={{ width: 24, height: 24, borderRadius: '50%' }} />
                      )}
                      {u.username}
                    </div>
                  </td>
                  <td style={{ fontFamily: 'monospace', fontSize: '0.75rem', color: '#8892b0' }}>
                    {u.steam_id}
                  </td>
                  <td>
                    <Badge bg={u.team === 'blue' ? 'primary' : u.team === 'red' ? 'danger' : 'secondary'}>
                      {u.team || 'None'}
                    </Badge>
                  </td>
                  <td>
                    <Form.Select
                      size="sm"
                      value={u.role}
                      onChange={(e) => handleRoleChange(u.id, e.target.value)}
                      style={{ ...inputStyle, width: 140, fontSize: '0.78rem' }}
                    >
                      {ROLES.map((r) => (
                        <option key={r} value={r} style={{ textTransform: 'capitalize' }}>{r}</option>
                      ))}
                    </Form.Select>
                  </td>
                  <td>
                    <div className="d-flex gap-1">
                      <Button
                        size="sm"
                        variant="outline-primary"
                        onClick={() => adminAPI.updateUserTeam(u.id, 'blue').then(fetchData)}
                      >
                        Blue
                      </Button>
                      <Button
                        size="sm"
                        variant="outline-danger"
                        onClick={() => adminAPI.updateUserTeam(u.id, 'red').then(fetchData)}
                      >
                        Red
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card.Body>
      </Card>

      {/* Pending Applications */}
      {applications.filter((a) => a.status === 'pending').length > 0 && (
        <Card style={panelStyle} className="mb-3">
          <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
            Pending Applications
          </Card.Header>
          <Card.Body className="p-0">
            <Table responsive variant="dark" className="mb-0" style={{ fontSize: '0.82rem' }}>
              <thead style={{ backgroundColor: '#0a0a1a' }}>
                <tr>
                  <th>Username</th>
                  <th>Requested Team</th>
                  <th>Date</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {applications
                  .filter((a) => a.status === 'pending')
                  .map((a) => (
                    <tr key={a.id}>
                      <td>{a.username}</td>
                      <td>
                        <Badge bg={a.requested_team === 'blue' ? 'primary' : 'danger'}>
                          {a.requested_team}
                        </Badge>
                      </td>
                      <td>{new Date(a.created_at).toLocaleDateString()}</td>
                      <td>
                        <div className="d-flex gap-1">
                          <Button size="sm" variant="success" onClick={() => handleApplication(a.id, 'approve')}>
                            Approve
                          </Button>
                          <Button size="sm" variant="outline-danger" onClick={() => handleApplication(a.id, 'reject')}>
                            Reject
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
              </tbody>
            </Table>
          </Card.Body>
        </Card>
      )}

      {/* Gamemaster Controls */}
      {isGamemaster && (
        <Card style={panelStyle} className="mb-3">
          <Card.Header style={{ backgroundColor: '#4a0e0e', fontWeight: 700, color: '#ffc107' }}>
            ⚠ Gamemaster Controls
          </Card.Header>
          <Card.Body>
            <Row className="g-3">
              <Col md={6}>
                <h6 style={{ color: '#ffc107' }}>Game Controls</h6>
                <div className="d-flex flex-wrap gap-2 mb-3">
                  <Button variant="success" onClick={handleStartGame}>
                    <FaPlay className="me-1" /> Start Game
                  </Button>
                  <Button variant="warning" onClick={handlePauseGame}>
                    <FaPause className="me-1" /> Pause Game
                  </Button>
                  <Button variant="outline-danger" onClick={handleResetGame}>
                    <FaUndo className="me-1" /> Reset Game
                  </Button>
                </div>

                <h6 style={{ color: '#ffc107' }}>Manual Tick Advance</h6>
                <Button
                  variant="outline-info"
                  size="sm"
                  onClick={() => gameAPI.advanceTick().then(() => alert('Tick advanced'))}
                >
                  Advance 1 Tick
                </Button>
              </Col>
              <Col md={6}>
                <h6 style={{ color: '#ffc107' }}>Override Tick</h6>
                <div className="d-flex gap-2 mb-3">
                  <Form.Control
                    type="number"
                    placeholder="Tick number"
                    value={tickOverride}
                    onChange={(e) => setTickOverride(e.target.value)}
                    style={{ ...inputStyle, width: 160 }}
                    size="sm"
                  />
                  <Button
                    variant="outline-warning"
                    size="sm"
                    disabled={!tickOverride}
                    onClick={() => {
                      alert(`Tick override to ${tickOverride} — requires backend implementation`);
                      setTickOverride('');
                    }}
                  >
                    Set
                  </Button>
                </div>

                <h6 style={{ color: '#ffc107' }}>Dangerous</h6>
                <Button
                  variant="outline-danger"
                  size="sm"
                  onClick={() => alert('Reverse last action — requires backend implementation')}
                >
                  <FaHistory className="me-1" /> Reverse Last Action
                </Button>
              </Col>
            </Row>
          </Card.Body>
        </Card>
      )}

      {/* Audit Log */}
      <Card style={panelStyle}>
        <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
          <FaHistory className="me-2" /> Audit Log
        </Card.Header>
        <Card.Body style={{ maxHeight: 400, overflowY: 'auto' }}>
          {auditLog.length === 0 ? (
            <div style={{ color: '#8892b0', fontStyle: 'italic' }}>No audit entries</div>
          ) : (
            <ListGroup variant="flush">
              {auditLog.map((entry) => (
                <ListGroup.Item
                  key={entry.id}
                  style={{
                    backgroundColor: 'transparent',
                    borderColor: '#0f3460',
                    color: '#ccd6f6',
                    fontSize: '0.8rem',
                  }}
                >
                  <div className="d-flex justify-content-between">
                    <span>
                      <strong style={{ color: '#64ffda' }}>{entry.user}</strong>
                      {' — '}
                      <Badge bg="dark">{entry.action}</Badge>
                    </span>
                    <span style={{ color: '#555', fontSize: '0.72rem' }}>
                      {new Date(entry.timestamp).toLocaleString()}
                    </span>
                  </div>
                  <div style={{ color: '#8892b0' }}>{entry.details}</div>
                </ListGroup.Item>
              ))}
            </ListGroup>
          )}
        </Card.Body>
      </Card>
    </div>
  );
}
