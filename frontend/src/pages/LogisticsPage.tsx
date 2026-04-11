import React, { useState, useEffect } from 'react';
import {
  Card, Tab, Tabs, Table, Badge, ProgressBar, Button, Spinner,
} from 'react-bootstrap';
import { FaTruck, FaTrain, FaShip, FaPlane, FaHelicopter } from 'react-icons/fa';
import { vehicleAPI, routeAPI } from '../services/api';
import VehicleCard from '../components/game/VehicleCard';
import type { Vehicle, Container, Route, VehicleType } from '../types';

const typeIcons: Record<VehicleType, React.ReactNode> = {
  truck: <FaTruck />, train: <FaTrain />, ship: <FaShip />,
  helicopter: <FaHelicopter />, plane: <FaPlane />, apc: <FaTruck />, tank: <FaTruck />,
};

export default function LogisticsPage() {
  const [vehicles, setVehicles] = useState<Vehicle[]>([]);
  const [routes, setRoutes] = useState<Route[]>([]);
  const [loading, setLoading] = useState(true);

  const fetchData = async () => {
    try {
      const [vRes, rRes] = await Promise.all([vehicleAPI.getAll(), routeAPI.getAll()]);
      setVehicles(vRes.data);
      setRoutes(rRes.data);
    } catch {}
    setLoading(false);
  };

  useEffect(() => { fetchData(); }, []);

  const handleDispatch = (id: number) => {
    const dest = prompt('Enter destination:');
    if (dest) vehicleAPI.move(id, dest).then(fetchData).catch(() => alert('Dispatch failed'));
  };

  const handleRepair = (id: number) => {
    vehicleAPI.repair(id).then(fetchData).catch(() => alert('Repair failed'));
  };

  // Collect all containers across vehicles
  const allContainers: Container[] = vehicles.flatMap((v) => v.cargo || []);

  // Active trips: vehicles that are moving
  const activeTrips = vehicles.filter((v) => v.is_moving);

  const panelStyle = { backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' };

  if (loading) {
    return <div className="d-flex justify-content-center py-5"><Spinner animation="border" variant="light" /></div>;
  }

  return (
    <Card style={panelStyle}>
      <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700, fontSize: '1.1rem' }}>
        Logistics Management
      </Card.Header>
      <Card.Body>
        <Tabs defaultActiveKey="vehicles" className="mb-3" variant="pills">
          {/* Vehicles Tab */}
          <Tab eventKey="vehicles" title={`Vehicles (${vehicles.length})`}>
            {vehicles.length === 0 ? (
              <div style={{ color: '#8892b0', fontStyle: 'italic' }}>No vehicles available</div>
            ) : (
              <div className="row g-3">
                {vehicles.map((v) => (
                  <div key={v.id} className="col-md-6 col-lg-4">
                    <VehicleCard vehicle={v} onDispatch={handleDispatch} onRepair={handleRepair} />
                  </div>
                ))}
              </div>
            )}
          </Tab>

          {/* Containers Tab */}
          <Tab eventKey="containers" title={`Containers (${allContainers.length})`}>
            {allContainers.length === 0 ? (
              <div style={{ color: '#8892b0', fontStyle: 'italic' }}>No containers tracked</div>
            ) : (
              <div className="row g-3">
                {allContainers.map((c) => (
                  <div key={c.id} className="col-md-4 col-lg-3">
                    <Card style={{ backgroundColor: '#0d1117', border: '1px solid #0f3460', color: '#ccd6f6' }}>
                      <Card.Body className="p-3">
                        <div className="d-flex justify-content-between mb-2">
                          <Badge bg="dark" style={{ textTransform: 'capitalize' }}>{c.type}</Badge>
                          <span style={{ fontSize: '0.75rem', color: '#8892b0' }}>HP: {c.health}%</span>
                        </div>
                        <ProgressBar now={c.health} variant={c.health > 50 ? 'success' : 'warning'} style={{ height: 4, marginBottom: 8, backgroundColor: '#0a0a1a' }} />
                        <div style={{ fontSize: '0.78rem' }}>
                          <div style={{ color: '#8892b0' }}>📍 {c.location}</div>
                          {c.contents.length > 0 ? (
                            c.contents.map((r) => (
                              <div key={r.id}>{r.name}: {r.quantity} {r.unit}</div>
                            ))
                          ) : (
                            <div style={{ color: '#555', fontStyle: 'italic' }}>Empty</div>
                          )}
                        </div>
                      </Card.Body>
                    </Card>
                  </div>
                ))}
              </div>
            )}
          </Tab>

          {/* Routes Tab */}
          <Tab eventKey="routes" title={`Routes (${routes.length})`}>
            <Table responsive variant="dark" style={{ fontSize: '0.82rem' }}>
              <thead style={{ backgroundColor: '#0a0a1a' }}>
                <tr>
                  <th>Name</th>
                  <th>Waypoints</th>
                  <th>Scheduled</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {routes.length === 0 ? (
                  <tr><td colSpan={4} className="text-center text-muted">No routes configured</td></tr>
                ) : (
                  routes.map((r) => (
                    <tr key={r.id}>
                      <td>{r.name}</td>
                      <td>
                        {r.waypoints.map((w) => w.location_name).join(' → ')}
                      </td>
                      <td>
                        <Badge bg={r.is_scheduled ? 'success' : 'secondary'}>
                          {r.is_scheduled ? `Every ${r.schedule_interval} ticks` : 'Manual'}
                        </Badge>
                      </td>
                      <td>
                        <Button
                          size="sm"
                          variant="outline-primary"
                          onClick={() => routeAPI.activate(r.id).then(fetchData).catch(() => {})}
                        >
                          Activate
                        </Button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </Table>
          </Tab>

          {/* Schedules Tab */}
          <Tab eventKey="schedules" title="Schedules">
            <Table responsive variant="dark" style={{ fontSize: '0.82rem' }}>
              <thead style={{ backgroundColor: '#0a0a1a' }}>
                <tr>
                  <th>Route</th>
                  <th>Interval</th>
                  <th>Vehicle</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {routes.filter((r) => r.is_scheduled).length === 0 ? (
                  <tr><td colSpan={4} className="text-center text-muted">No automated schedules</td></tr>
                ) : (
                  routes.filter((r) => r.is_scheduled).map((r) => {
                    const v = vehicles.find((veh) => veh.id === r.vehicle_id);
                    return (
                      <tr key={r.id}>
                        <td>{r.name}</td>
                        <td>{r.schedule_interval} ticks</td>
                        <td>{v ? v.name : `#${r.vehicle_id}`}</td>
                        <td><Badge bg="success">Active</Badge></td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </Table>
          </Tab>

          {/* Trips Tab */}
          <Tab eventKey="trips" title={`Trips (${activeTrips.length})`}>
            {activeTrips.length === 0 ? (
              <div style={{ color: '#8892b0', fontStyle: 'italic' }}>No active trips</div>
            ) : (
              <Table responsive variant="dark" style={{ fontSize: '0.82rem' }}>
                <thead style={{ backgroundColor: '#0a0a1a' }}>
                  <tr>
                    <th>Vehicle</th>
                    <th>Type</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {activeTrips.map((v) => (
                    <tr key={v.id}>
                      <td>{v.name}</td>
                      <td>{typeIcons[v.vehicle_type]} <span style={{ textTransform: 'capitalize' }}>{v.vehicle_type}</span></td>
                      <td>{v.location}</td>
                      <td>{v.destination || '—'}</td>
                      <td><Badge bg="primary">In Transit</Badge></td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Tab>
        </Tabs>
      </Card.Body>
    </Card>
  );
}
