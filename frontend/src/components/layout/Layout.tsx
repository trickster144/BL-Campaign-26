import React from 'react';
import { Outlet } from 'react-router-dom';
import Navbar from './Navbar';
import Sidebar from './Sidebar';

const Layout: React.FC = () => {
  return (
    <div className="bl-layout" style={{ minHeight: '100vh', background: '#1a1a2e' }}>
      <Navbar />
      <div className="d-flex" style={{ paddingTop: 62 }}>
        <Sidebar />
        <main
          className="bl-main-content flex-grow-1"
          style={{
            marginLeft: 260,
            padding: '1.5rem',
            minHeight: 'calc(100vh - 62px)',
            transition: 'margin-left 0.3s ease',
          }}
        >
          <Outlet />
        </main>
      </div>
    </div>
  );
};

export default Layout;
