import { RowDataPacket } from 'mysql2/promise';
import { query, execute } from './databaseConfig.js';
import { EDUCATION } from './gameConstants.js';

// ── Types ────────────────────────────────────────────────────────────────────
interface EducationQueue extends RowDataPacket {
  id: number;
  location_id: number;
  education_type: string;
  enrolled_count: number;
  start_tick: number;
  teacher_count: number;
  progress_ticks: number;
}

interface EducationStatus {
  locationId: number;
  school: { enrolled: number; teachers: number; progressPercent: number } | null;
  college: { enrolled: number; professors: number; progressPercent: number } | null;
  officerSchool: { enrolled: number; instructors: number; progressPercent: number } | null;
}

// We track education queues in-memory and sync with DB
// DB table: education_queues (created if needed via ensureTable)

async function ensureTable(): Promise<void> {
  await execute(`
    CREATE TABLE IF NOT EXISTS education_queues (
      id INT PRIMARY KEY AUTO_INCREMENT,
      location_id INT NOT NULL,
      education_type ENUM('school','college','officer_school') NOT NULL,
      enrolled_count INT DEFAULT 0,
      teacher_count INT DEFAULT 0,
      start_tick BIGINT NOT NULL,
      progress_ticks BIGINT DEFAULT 0,
      completed BOOLEAN DEFAULT FALSE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
    )
  `);
}

// ── Enrollment ───────────────────────────────────────────────────────────────

export async function enrollInSchool(locationId: number, count: number): Promise<{ success: boolean; message?: string }> {
  await ensureTable();

  // Check available uneducated adults
  const rows = await query<(RowDataPacket & { available: number })[]>(
    `SELECT COALESCE(SUM(\`count\`), 0) AS available
     FROM population_groups
     WHERE location_id = ? AND age_group IN ('children','adults') AND education_level = 'none'`,
    [locationId],
  );
  const available = Number(rows[0]?.available ?? 0);
  if (available < count) {
    return { success: false, message: `Only ${available} uneducated people available` };
  }

  // Get current tick
  const tickRows = await query<(RowDataPacket & { current_tick: number })[]>(
    'SELECT current_tick FROM game_state WHERE id = 1',
  );
  const currentTick = Number(tickRows[0]?.current_tick ?? 0);

  await execute(
    `INSERT INTO education_queues (location_id, education_type, enrolled_count, teacher_count, start_tick)
     VALUES (?, 'school', ?, 0, ?)`,
    [locationId, count, currentTick],
  );

  return { success: true };
}

export async function enrollInCollege(locationId: number, count: number): Promise<{ success: boolean; message?: string }> {
  await ensureTable();

  const rows = await query<(RowDataPacket & { available: number })[]>(
    `SELECT COALESCE(SUM(\`count\`), 0) AS available
     FROM population_groups
     WHERE location_id = ? AND age_group = 'adults' AND education_level = 'primary'`,
    [locationId],
  );
  const available = Number(rows[0]?.available ?? 0);
  if (available < count) {
    return { success: false, message: `Only ${available} primary-educated adults available` };
  }

  const tickRows = await query<(RowDataPacket & { current_tick: number })[]>(
    'SELECT current_tick FROM game_state WHERE id = 1',
  );
  const currentTick = Number(tickRows[0]?.current_tick ?? 0);

  await execute(
    `INSERT INTO education_queues (location_id, education_type, enrolled_count, teacher_count, start_tick)
     VALUES (?, 'college', ?, 0, ?)`,
    [locationId, count, currentTick],
  );

  return { success: true };
}

export async function enrollInOfficerSchool(locationId: number, count: number): Promise<{ success: boolean; message?: string }> {
  await ensureTable();

  const rows = await query<(RowDataPacket & { available: number })[]>(
    `SELECT COALESCE(SUM(\`count\`), 0) AS available
     FROM population_groups
     WHERE location_id = ? AND age_group = 'adults' AND education_level = 'advanced'`,
    [locationId],
  );
  const available = Number(rows[0]?.available ?? 0);
  if (available < count) {
    return { success: false, message: `Only ${available} advanced-educated adults available` };
  }

  const tickRows = await query<(RowDataPacket & { current_tick: number })[]>(
    'SELECT current_tick FROM game_state WHERE id = 1',
  );
  const currentTick = Number(tickRows[0]?.current_tick ?? 0);

  await execute(
    `INSERT INTO education_queues (location_id, education_type, enrolled_count, teacher_count, start_tick)
     VALUES (?, 'officer_school', ?, 0, ?)`,
    [locationId, count, currentTick],
  );

  return { success: true };
}

// ── Process education (called each tick) ─────────────────────────────────────

export async function processEducation(tick: number): Promise<void> {
  await ensureTable();

  const queues = await query<EducationQueue[]>(
    'SELECT * FROM education_queues WHERE completed = FALSE',
  );

  for (const q of queues) {
    let durationTicks: number;
    let teacherRatio: number;
    let speedBonus = 0;

    switch (q.education_type) {
      case 'school':
        durationTicks = EDUCATION.school_duration_ticks;
        teacherRatio = EDUCATION.school_teacher_ratio;
        // Speed bonus: 3% per empty seat below 30
        if (q.enrolled_count < teacherRatio) {
          const emptySeats = teacherRatio - q.enrolled_count;
          speedBonus = emptySeats * EDUCATION.school_speed_bonus_per_empty_seat;
        }
        break;
      case 'college':
        durationTicks = EDUCATION.college_duration_ticks;
        teacherRatio = EDUCATION.college_professor_ratio;
        break;
      case 'officer_school':
        durationTicks = EDUCATION.officer_school_duration_ticks;
        teacherRatio = 10;
        break;
      default:
        continue;
    }

    // Increment progress (1 + speed bonus per tick)
    const progressIncrement = 1 + speedBonus;
    const newProgress = Number(q.progress_ticks) + progressIncrement;

    if (newProgress >= durationTicks) {
      // Graduate students
      let newLevel: string;
      switch (q.education_type) {
        case 'school':        newLevel = 'primary'; break;
        case 'college':       newLevel = 'advanced'; break;
        case 'officer_school': newLevel = 'advanced'; break;
        default:              newLevel = 'primary';
      }

      // Update population education levels
      const prevLevel = q.education_type === 'school' ? 'none' : (q.education_type === 'college' ? 'primary' : 'advanced');
      await execute(
        `UPDATE population_groups SET \`count\` = \`count\` - ?
         WHERE location_id = ? AND age_group = 'adults' AND education_level = ?`,
        [q.enrolled_count, q.location_id, prevLevel],
      );
      // Ensure target group exists
      const existing = await query<(RowDataPacket & { id: number })[]>(
        `SELECT id FROM population_groups
         WHERE location_id = ? AND age_group = 'adults' AND education_level = ?`,
        [q.location_id, newLevel],
      );
      if (existing.length === 0) {
        await execute(
          `INSERT INTO population_groups (location_id, age_group, \`count\`, education_level)
           VALUES (?, 'adults', ?, ?)`,
          [q.location_id, q.enrolled_count, newLevel],
        );
      } else {
        await execute(
          `UPDATE population_groups SET \`count\` = \`count\` + ?
           WHERE location_id = ? AND age_group = 'adults' AND education_level = ?`,
          [q.enrolled_count, q.location_id, newLevel],
        );
      }

      // Mark complete
      await execute(
        'UPDATE education_queues SET completed = TRUE, progress_ticks = ? WHERE id = ?',
        [newProgress, q.id],
      );
    } else {
      await execute(
        'UPDATE education_queues SET progress_ticks = ? WHERE id = ?',
        [newProgress, q.id],
      );
    }
  }
}

// ── Status ───────────────────────────────────────────────────────────────────

export async function getEducationStatus(locationId: number): Promise<EducationStatus> {
  await ensureTable();

  const queues = await query<EducationQueue[]>(
    'SELECT * FROM education_queues WHERE location_id = ? AND completed = FALSE',
    [locationId],
  );

  const status: EducationStatus = { locationId, school: null, college: null, officerSchool: null };

  for (const q of queues) {
    const duration = q.education_type === 'school' ? EDUCATION.school_duration_ticks
      : q.education_type === 'college' ? EDUCATION.college_duration_ticks
      : EDUCATION.officer_school_duration_ticks;
    const pct = Math.min(100, (Number(q.progress_ticks) / duration) * 100);

    const entry = {
      enrolled: q.enrolled_count,
      teachers: q.teacher_count,
      professors: q.teacher_count,
      instructors: q.teacher_count,
      progressPercent: Math.round(pct * 10) / 10,
    };

    switch (q.education_type) {
      case 'school':         status.school = entry; break;
      case 'college':        status.college = entry; break;
      case 'officer_school': status.officerSchool = entry; break;
    }
  }

  return status;
}
