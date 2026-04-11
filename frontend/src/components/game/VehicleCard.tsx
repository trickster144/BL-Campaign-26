import React from 'react';
import { Card, Badge, ProgressBar } from 'react-bootstrap';
import { FaTruck, FaTrain, FaShip, FaPlane, FaHelicopter } from 'react-icons/fa';
import type { Vehicle, VehicleType, VehicleStatus } from '../../types';

interface VehicleCardProps {
  vehicle: Vehicle;
  onDispatch?: (id: number) => void;
  onRepair?: (id: number) => void;
}

const vehicleIcons: Record<VehicleType, React.ReactNode> = {
  truck: <FaTruck />,
  train: <FaTrain />,
  ship: <FaShip />,
  helicopter: <FaHelicopter />,
  plane: <FaPlane />,
  apc: <FaTruck />,
  tank: <FaTruck />,
};

const statusColors: Record<VehicleStatus, string> = {
  idle: 'secondary',
  moving: 'primary',
  loading: 'warning',
  unloading: 'info',
  repairing: 'warning',
  destroyed: 'danger',
};

function healthVariant(hp: number) {
  if (hp > 60) return 'success';
  if (hp > 30) return 'warning';
  return 'danger';
}

export default function VehicleCard({ vehicle, onDispatch, onRepair }: VehicleCardProps) {
  const cargoCount = vehicle.cargo?.reduce(
    (sum, c) => sum + c.contents.reduce((s, r) => s + r.quantity, 0),
    0
  ) ?? 0;

  return (
    <Card
      className="h-100"
      style={{ backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' }}
    >
      <Card.Body className="p-3">
        <div className="d-flex justify-content-between align-items-start mb-2">
          <div className="d-flex align-items-center gap-2">
            <span style={{ fontSize: '1.4rem', color: '#64ffda' }}>
              {vehicleIcons[vehicle.vehicle_type]}
            </span>
            <div>
              <div style={{ fontWeight: 600, fontSize: '0.9rem' }}>{vehicle.name}</div>
              <small style={{ color: '#8892b0', textTransform: 'capitalize' }}>
                {vehicle.vehicle_type}
              </small>
            </div>
          </div>
          <Badge bg={statusColors[vehicle.status]} style={{ textTransform: 'capitalize' }}>
            {vehicle.status}
          </Badge>
        </div>

        <div className="mb-2">
          <small style={{ color: '#8892b0' }}>Health</small>
          <ProgressBar
            now={vehicle.health}
            variant={healthVariant(vehicle.health)}
            style={{ height: 6, backgroundColor: '#0a0a1a' }}
          />
        </div>

        <div className="mb-2">
          <small style={{ color: '#8892b0' }}>Fuel</small>
          <ProgressBar
            now={vehicle.fuel_level}
            variant={vehicle.fuel_level > 30 ? 'info' : 'danger'}
            style={{ height: 6, backgroundColor: '#0a0a1a' }}
          />
        </div>

        <div
          className="d-flex justify-content-between"
          style={{ fontSize: '0.75rem', color: '#8892b0' }}
        >
          <span>📍 {vehicle.location}</span>
          <span>📦 {cargoCount} items</span>
        </div>

        {vehicle.destination && (
          <div style={{ fontSize: '0.75rem', color: '#5dade2', marginTop: 4 }}>
            → {vehicle.destination}
          </div>
        )}

        <div className="d-flex gap-2 mt-2">
          {onDispatch && vehicle.status === 'idle' && (
            <button
              className="btn btn-sm btn-outline-primary flex-fill"
              onClick={() => onDispatch(vehicle.id)}
            >
              Dispatch
            </button>
          )}
          {onRepair && vehicle.health < 100 && vehicle.status !== 'destroyed' && (
            <button
              className="btn btn-sm btn-outline-warning flex-fill"
              onClick={() => onRepair(vehicle.id)}
            >
              Repair
            </button>
          )}
        </div>
      </Card.Body>
    </Card>
  );
}
