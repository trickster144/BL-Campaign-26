import { Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './context/AuthContext'
import { GameProvider } from './context/GameContext'
import Layout from './components/layout/Layout'
import LoginPage from './pages/LoginPage'
import AuthCallbackPage from './pages/AuthCallbackPage'
import DashboardPage from './pages/DashboardPage'
import MapPage from './pages/MapPage'
import ResourcesPage from './pages/ResourcesPage'
import LogisticsPage from './pages/LogisticsPage'
import MilitaryPage from './pages/MilitaryPage'
import MarketPage from './pages/MarketPage'
import FarmPage from './pages/FarmPage'
import EspionagePage from './pages/EspionagePage'
import AdminPage from './pages/AdminPage'
import RulesPage from './pages/RulesPage'

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, isLoading } = useAuth()
  if (isLoading) {
    return (
      <div className="d-flex justify-content-center align-items-center vh-100 bg-dark">
        <div className="spinner-border text-light" role="status">
          <span className="visually-hidden">Loading...</span>
        </div>
      </div>
    )
  }
  return isAuthenticated ? <>{children}</> : <Navigate to="/login" replace />
}

function AdminRoute({ children }: { children: React.ReactNode }) {
  const { role } = useAuth()
  if (role !== 'admin' && role !== 'gamemaster') return <Navigate to="/dashboard" replace />
  return <>{children}</>
}

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/auth/callback" element={<AuthCallbackPage />} />
      <Route path="/" element={
        <ProtectedRoute>
          <GameProvider>
            <Layout />
          </GameProvider>
        </ProtectedRoute>
      }>
        <Route index element={<Navigate to="/dashboard" replace />} />
        <Route path="dashboard" element={<DashboardPage />} />
        <Route path="map" element={<MapPage />} />
        <Route path="resources" element={<ResourcesPage />} />
        <Route path="logistics" element={<LogisticsPage />} />
        <Route path="military" element={<MilitaryPage />} />
        <Route path="market" element={<MarketPage />} />
        <Route path="farms" element={<FarmPage />} />
        <Route path="espionage" element={<EspionagePage />} />
        <Route path="rules" element={<RulesPage />} />
        <Route path="admin" element={<AdminRoute><AdminPage /></AdminRoute>} />
      </Route>
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}

function App() {
  return (
    <AuthProvider>
      <AppRoutes />
    </AuthProvider>
  )
}

export default App