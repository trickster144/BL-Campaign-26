import express from 'express';
import cors from 'cors';
import helmet from 'helmet';
import compression from 'compression';
import morgan from 'morgan';
import rateLimit from 'express-rate-limit';
import { createServer } from 'http';
import { Server } from 'socket.io';
import dotenv from 'dotenv';

// Load environment variables
dotenv.config();

// Database & tick engine
import { testConnection } from './databaseConfig.js';
import { startTickEngine, stopTickEngine } from './tickEngine.js';

// Routes
import gameRoutes from './routes/gameRoutes.js';
import authRoutes from './routes/authRoutes.js';
import resourceRoutes from './routes/resourceRoutes.js';
import logisticsRoutes from './routes/logisticsRoutes.js';
import combatRoutes from './routes/combatRoutes.js';
import mapRoutes from './routes/mapRoutes.js';
import adminRoutes from './routes/adminRoutes.js';

// ── Express app setup ────────────────────────────────────────────────────────
const app = express();
const server = createServer(app);

const FRONTEND_URL = process.env.FRONTEND_URL || 'http://10.0.0.28:10011';

const io = new Server(server, {
  cors: {
    origin: FRONTEND_URL,
    methods: ['GET', 'POST'],
    credentials: true,
  },
});

// ── Middleware ────────────────────────────────────────────────────────────────
app.use(helmet());
app.use(cors({ origin: FRONTEND_URL, credentials: true }));
app.use(compression());
app.use(morgan('combined'));
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true }));

// Rate limiting
const apiLimiter = rateLimit({
  windowMs: 15 * 60 * 1000, // 15 minutes
  max: 1000,
  standardHeaders: true,
  legacyHeaders: false,
});
app.use('/api/', apiLimiter);

// ── Health check ─────────────────────────────────────────────────────────────
app.get('/api/health', (_req, res) => {
  res.json({ status: 'OK', timestamp: new Date().toISOString() });
});

// ── API Routes ───────────────────────────────────────────────────────────────
app.use('/api/game', gameRoutes);
app.use('/api/auth', authRoutes);
app.use('/api/resources', resourceRoutes);
app.use('/api/logistics', logisticsRoutes);
app.use('/api/combat', combatRoutes);
app.use('/api/map', mapRoutes);
app.use('/api/admin', adminRoutes);

// ── Socket.io ────────────────────────────────────────────────────────────────
io.on('connection', (socket) => {
  console.log('🔌 User connected:', socket.id);

  // Join team room based on auth data
  socket.on('join_team', (team: string) => {
    if (team === 'blue' || team === 'red') {
      socket.join(`team:${team}`);
      console.log(`  └─ ${socket.id} joined team:${team}`);
    }
  });

  // Join admin/gamemaster room
  socket.on('join_admin', () => {
    socket.join('admin');
  });

  socket.on('disconnect', () => {
    console.log('🔌 User disconnected:', socket.id);
  });
});

// ── Startup ──────────────────────────────────────────────────────────────────
const PORT = process.env.PORT || 10012;

async function startup(): Promise<void> {
  // Start HTTP server
  server.listen(PORT, () => {
    console.log(`🌐 Server running on port ${PORT}`);
    console.log(`   Frontend URL: ${FRONTEND_URL}`);
  });

  // Keep auth and other lightweight routes available even if the simulation stack
  // cannot initialize yet.
  const dbOk = await testConnection();
  if (!dbOk) {
    console.error('⚠️ Database connection failed. Server is running in degraded mode; tick engine not started.');
    return;
  }

  try {
    await startTickEngine(io);
  } catch (err) {
    console.error('⚠️ Tick engine failed to start. Server is running without simulation processing:', err);
  }
}

// Graceful shutdown
process.on('SIGINT', () => {
  console.log('\n🛑 Shutting down...');
  stopTickEngine();
  server.close(() => {
    console.log('✅ Server closed');
    process.exit(0);
  });
});

process.on('SIGTERM', () => {
  stopTickEngine();
  server.close(() => process.exit(0));
});

startup().catch((err) => {
  console.error('❌ Startup failed:', err);
  process.exit(1);
});

export { app, io };
