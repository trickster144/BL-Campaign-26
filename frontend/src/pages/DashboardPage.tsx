import React, { useEffect, useState } from 'react';
import { Container, Row, Col, Card, Badge, Spinner, Button } from 'react-bootstrap';
import { Link } from 'react-router-dom';
import {
  FaClock, FaCloudSun, FaLeaf, FaCubes, FaTruck, FaShieldAlt,
  FaCrosshairs, FaNewspaper, FaMapMarkedAlt, FaIndustry,
} from 'react-icons/fa';
import { useAuth } from '../context/AuthContext';
import { useGame } from '../context/GameContext';
import { resourceAPI, vehicleAPI, armyAPI, battleAPI } from '../services/api';
import type { Resource, Vehicle, Army, Battle } from '../types';
import WeatherWidget from '../components/game/WeatherWidget';

const DashboardPage: React.FC = () => {
  const { user, team } = useAuth();
  const { gameState, weather } = useGame();
  const teamColor = team === 'blue' ? '#1e90ff' : team === 'red' ? '#dc3545' : '#6c757d';

  const [resources, setResources] = useState<Resource[]>([]);
  const [vehicles, setVehicles] = useState<Vehicle[]>([]);
  const [armies, setArmies] = useState<Army[]>([]);
  const [battles, setBattles] = useState<Battle[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const load = async () => {
      try {
        const [resR, vehR, armR, batR] = await Promise.all([
          resourceAPI.getAll(),
          vehicleAPI.getAll(),
          armyAPI.getAll(),
          battleAPI.getActive(),
        ]);
        setResources(resR.data);
        setVehicles(vehR.data);
        setArmies(armR.data);
        setBattles(batR.data);
      } catch {
        // API not available – continue with empty data
      } finally {
        setLoading(false);
      }
    };
    load();
  }, []);

  // Critical resources (below 25% capacity)
  const criticalResources = resources
    .filter((r) => r.max_capacity > 0 && r.quantity / r.max_capacity < 0.25)
    .slice(0, 5);

  const activeVehicles = vehicles.filter((v) => v.is_moving);
  const teamArmies = armies.filter((a) => a.team === team);
  const activeBattles = battles.filter((b) => b.status === 'in_progress');

  if (loading) {
    return (
      <div className="d-flex justify-content-center align-items-center" style={{ minHeight: '60vh' }}>
        <Spinner animation="border" style={{ color: teamColor }} />
      </div>
    );
  }

  return (
    <Container fluid className="py-3">
      {/* Welcome */}
      <div className="mb-4">
        <h2 className="fw-bold" style={{ color: '#e0e0e0' }}>
          Welcome back,{' '}
          <span style={{ color: teamColor }}>{user?.username || 'Commander'}</span>
        </h2>
        <p className="text-muted mb-0">
          {team ? `${team.charAt(0).toUpperCase() + team.slice(1)} Team Command Center` : 'Unassigned — contact an admin for team placement'}
        </p>
      </div>

      {/* Status cards */}
      <Row className="g-3 mb-4">
        <Col md={3}>
          <StatusCard
            icon={<FaClock />}
            label="Current Tick"
            value={gameState?.current_tick?.toString() || '—'}
            color="#17a2b8"
            sub={gameState?.game_started ? 'Game Active' : 'Game Paused'}
          />
        </Col>
        <Col md={3}>
          <StatusCard
            icon={<FaLeaf />}
            label="Season"
            value={gameState?.season?.toUpperCase() || '—'}
            color="#66bb6a"
          />
        </Col>
        <Col md={3}>
          <StatusCard
            icon={<FaCloudSun />}
            label="Weather"
            value={weather?.type?.toUpperCase() || '—'}
            color="#ffd700"
            sub={weather ? `${weather.temperature}°C` : ''}
          />
        </Col>
        <Col md={3}>
          <StatusCard
            icon={<FaCrosshairs />}
            label="Active Battles"
            value={activeBattles.length.toString()}
            color="#dc3545"
            sub={activeBattles.length > 0 ? 'COMBAT ALERT' : 'All quiet'}
          />
        </Col>
      </Row>

      <Row className="g-3 mb-4">
        {/* Critical Resources */}
        <Col md={6}>
          <Card className="bl-card h-100" style={{ background: '#16213e', border: '1px solid #ffffff10' }}>
            <Card.Header className="d-flex align-items-center gap-2 border-0" style={{ background: '#0f0f23', color: '#e0e0e0' }}>
              <FaCubes style={{ color: '#ffc107' }} />
              <span className="fw-bold">Critical Resources</span>
            </Card.Header>
            <Card.Body>
              {criticalResources.length === 0 ? (
                <div className="text-muted text-center py-3">All resources at healthy levels ✓</div>
              ) : (
                criticalResources.map((r) => {
                  const pct = Math.round((r.quantity / r.max_capacity) * 100);
                  return (
                    <div key={r.id} className="d-flex justify-content-between align-items-center mb-2">
                      <span style={{ color: '#ccc', fontSize: '0.85rem' }}>{r.name}</span>
                      <div className="d-flex align-items-center gap-2">
                        <div
                          style={{
                            width: 80,
                            height: 6,
                            background: '#0f0f23',
                            borderRadius: 3,
                            overflow: 'hidden',
                          }}
                        >
                          <div
                            style={{
                              width: `${pct}%`,
                              height: '100%',
                              background: pct < 10 ? '#dc3545' : '#ffc107',
                              borderRadius: 3,
                            }}
                          />
                        </div>
                        <Badge bg={pct < 10 ? 'danger' : 'warning'} style={{ fontSize: '0.65rem' }}>
                          {pct}%
                        </Badge>
                      </div>
                    </div>
                  );
                })
              )}
            </Card.Body>
          </Card>
        </Col>

        {/* Vehicle & Army overview */}
        <Col md={3}>
          <Card className="bl-card h-100" style={{ background: '#16213e', border: '1px solid #ffffff10' }}>
            <Card.Header className="border-0" style={{ background: '#0f0f23', color: '#e0e0e0' }}>
              <FaTruck style={{ color: '#5bc0de' }} className="me-2" />
              <span className="fw-bold">Vehicles</span>
            </Card.Header>
            <Card.Body className="text-center">
              <div style={{ fontSize: '2.5rem', fontWeight: 700, color: teamColor }}>
                {vehicles.length}
              </div>
              <div className="text-muted mb-2">Total Fleet</div>
              <Badge bg="primary" className="me-1">{activeVehicles.length} moving</Badge>
              <Badge bg="secondary">{vehicles.length - activeVehicles.length} idle</Badge>
            </Card.Body>
          </Card>
        </Col>
        <Col md={3}>
          <Card className="bl-card h-100" style={{ background: '#16213e', border: '1px solid #ffffff10' }}>
            <Card.Header className="border-0" style={{ background: '#0f0f23', color: '#e0e0e0' }}>
              <FaShieldAlt style={{ color: '#dc3545' }} className="me-2" />
              <span className="fw-bold">Army</span>
            </Card.Header>
            <Card.Body className="text-center">
              <div style={{ fontSize: '2.5rem', fontWeight: 700, color: teamColor }}>
                {teamArmies.length}
              </div>
              <div className="text-muted mb-2">Armies</div>
              <div style={{ color: '#aaa', fontSize: '0.85rem' }}>
                Total strength: {teamArmies.reduce((s, a) => s + a.strength, 0).toLocaleString()}
              </div>
            </Card.Body>
          </Card>
        </Col>
      </Row>

      {/* Bottom row */}
      <Row className="g-3">
        {/* Weather details */}
        <Col md={4}>
          <Card className="bl-card h-100" style={{ background: '#16213e', border: '1px solid #ffffff10' }}>
            <Card.Header className="border-0" style={{ background: '#0f0f23', color: '#e0e0e0' }}>
              <FaCloudSun className="me-2" style={{ color: '#ffd700' }} />
              <span className="fw-bold">Weather Report</span>
            </Card.Header>
            <Card.Body>
              <WeatherWidget />
            </Card.Body>
          </Card>
        </Col>

        {/* Recent battle activity */}
        <Col md={4}>
          <Card className="bl-card h-100" style={{ background: '#16213e', border: '1px solid #ffffff10' }}>
            <Card.Header className="border-0" style={{ background: '#0f0f23', color: '#e0e0e0' }}>
              <FaNewspaper className="me-2" style={{ color: '#17a2b8' }} />
              <span className="fw-bold">Recent Activity</span>
            </Card.Header>
            <Card.Body>
              {battles.length === 0 ? (
                <div className="text-muted text-center py-3">No recent battles</div>
              ) : (
                battles.slice(0, 5).map((b) => (
                  <div key={b.id} className="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom border-dark">
                    <div>
                      <div style={{ color: '#ccc', fontSize: '0.85rem' }}>
                        <span style={{ color: '#1e90ff' }}>{b.attacker}</span>
                        {' vs '}
                        <span style={{ color: '#dc3545' }}>{b.defender}</span>
                      </div>
                      <small className="text-muted">{b.location}</small>
                    </div>
                    <Badge bg={b.status === 'in_progress' ? 'danger' : b.status === 'completed' ? 'secondary' : 'warning'}>
                      {b.status.replace('_', ' ')}
                    </Badge>
                  </div>
                ))
              )}
            </Card.Body>
          </Card>
        </Col>

        {/* Quick actions */}
        <Col md={4}>
          <Card className="bl-card h-100" style={{ background: '#16213e', border: '1px solid #ffffff10' }}>
            <Card.Header className="border-0" style={{ background: '#0f0f23', color: '#e0e0e0' }}>
              <span className="fw-bold">⚡ Quick Actions</span>
            </Card.Header>
            <Card.Body className="d-flex flex-column gap-2">
              <Button as={Link as any} to="/map" variant="outline-primary" className="text-start" size="sm">
                <FaMapMarkedAlt className="me-2" /> Open Strategic Map
              </Button>
              <Button as={Link as any} to="/logistics" variant="outline-info" className="text-start" size="sm">
                <FaTruck className="me-2" /> Manage Logistics
              </Button>
              <Button as={Link as any} to="/military" variant="outline-danger" className="text-start" size="sm">
                <FaShieldAlt className="me-2" /> Military Command
              </Button>
              <Button as={Link as any} to="/resources" variant="outline-warning" className="text-start" size="sm">
                <FaIndustry className="me-2" /> Resource Management
              </Button>
              <Button as={Link as any} to="/market" variant="outline-success" className="text-start" size="sm">
                <FaCubes className="me-2" /> Global Market
              </Button>
            </Card.Body>
          </Card>
        </Col>
      </Row>
    </Container>
  );
};

interface StatusCardProps {
  icon: React.ReactNode;
  label: string;
  value: string;
  color: string;
  sub?: string;
}

const StatusCard: React.FC<StatusCardProps> = ({ icon, label, value, color, sub }) => (
  <Card style={{ background: '#16213e', border: '1px solid #ffffff10' }}>
    <Card.Body className="d-flex align-items-center gap-3 py-3">
      <div
        className="d-flex align-items-center justify-content-center rounded"
        style={{ width: 48, height: 48, background: `${color}22`, color, fontSize: '1.3rem' }}
      >
        {icon}
      </div>
      <div>
        <div className="text-muted" style={{ fontSize: '0.7rem', textTransform: 'uppercase', letterSpacing: '1px' }}>
          {label}
        </div>
        <div className="fw-bold" style={{ color: '#e0e0e0', fontSize: '1.2rem' }}>{value}</div>
        {sub && <small style={{ color: '#888', fontSize: '0.7rem' }}>{sub}</small>}
      </div>
    </Card.Body>
  </Card>
);

export default DashboardPage;
