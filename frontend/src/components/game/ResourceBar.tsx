import React from 'react';
import { ProgressBar } from 'react-bootstrap';

interface ResourceBarProps {
  name: string;
  quantity: number;
  maxCapacity: number;
  unit?: string;
  category?: string;
  showLabel?: boolean;
}

const categoryIcons: Record<string, string> = {
  raw: '⛏️',
  processed: '⚙️',
  military: '🎯',
  food: '🌾',
  fuel: '⛽',
  medical: '💊',
};

const ResourceBar: React.FC<ResourceBarProps> = ({
  name, quantity, maxCapacity, unit = '', category, showLabel = true,
}) => {
  const pct = maxCapacity > 0 ? Math.min((quantity / maxCapacity) * 100, 100) : 0;
  const variant = pct > 70 ? 'success' : pct > 30 ? 'warning' : 'danger';
  const icon = category ? categoryIcons[category] || '📦' : '📦';

  return (
    <div className="mb-2">
      {showLabel && (
        <div className="d-flex justify-content-between align-items-center mb-1">
          <span style={{ fontSize: '0.8rem', color: '#ccc' }}>
            {icon} {name}
          </span>
          <span style={{ fontSize: '0.75rem', color: '#999' }}>
            {quantity.toLocaleString()} / {maxCapacity.toLocaleString()} {unit}
          </span>
        </div>
      )}
      <ProgressBar
        now={pct}
        variant={variant}
        style={{ height: 8, backgroundColor: '#1a1a2e', borderRadius: 4 }}
      />
    </div>
  );
};

export default ResourceBar;
