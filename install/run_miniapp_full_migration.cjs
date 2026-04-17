/**
 * Runs install/miniapp_full_migration.sql + additive column patches + seed data.
 * Idempotent — safe to run multiple times.
 *
 *   node install/run_miniapp_full_migration.cjs
 */
const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');

const DB = {
  host: process.env.DB_HOST || '118.27.146.16',
  port: Number(process.env.DB_PORT || 3306),
  user: process.env.DB_USER || 'zrismpsz_clinicya',
  password: process.env.DB_PASSWORD || 'zrismpsz_clinicya',
  database: process.env.DB_NAME || 'zrismpsz_clinicya',
  multipleStatements: true,
};

const log = (icon, msg) => console.log(`${icon}  ${msg}`);

async function columnExists(conn, table, col) {
  const [rows] = await conn.query(
    `SELECT COUNT(*) AS c FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?`,
    [DB.database, table, col]
  );
  return rows[0].c > 0;
}

async function addColumnIfMissing(conn, table, col, ddl) {
  if (await columnExists(conn, table, col)) {
    log('•', `${table}.${col} already exists — skip`);
  } else {
    await conn.query(`ALTER TABLE \`${table}\` ADD COLUMN ${ddl}`);
    log('+', `${table}.${col} added`);
  }
}

async function tableHasRows(conn, table) {
  const [rows] = await conn.query(`SELECT COUNT(*) AS c FROM \`${table}\``);
  return rows[0].c > 0;
}

(async () => {
  const conn = await mysql.createConnection(DB);
  log('>', `Connected to ${DB.user}@${DB.host}:${DB.port}/${DB.database}`);

  // ---------------------------------------------------------------------------
  // 1. Run CREATE TABLE IF NOT EXISTS from SQL file
  // ---------------------------------------------------------------------------
  const sqlPath = path.join(__dirname, 'miniapp_full_migration.sql');
  const sql = fs.readFileSync(sqlPath, 'utf8');
  await conn.query(sql);
  log('✔', 'DDL from miniapp_full_migration.sql executed');

  // ---------------------------------------------------------------------------
  // 2. Additive column patches (not safe with plain IF NOT EXISTS on MySQL < 8)
  // ---------------------------------------------------------------------------
  await addColumnIfMissing(
    conn,
    'points_history',
    'line_account_id',
    '`line_account_id` INT DEFAULT NULL AFTER `id`'
  );
  // index for line_account_id (skip if already exists)
  try {
    await conn.query(`CREATE INDEX idx_ph_line_account ON points_history (line_account_id)`);
    log('+', 'points_history.idx_ph_line_account index added');
  } catch (e) {
    if (!/Duplicate key name/.test(e.message)) throw e;
  }

  await addColumnIfMissing(
    conn,
    'shop_settings',
    'logo_url',
    '`logo_url` VARCHAR(500) DEFAULT NULL AFTER `shop_logo`'
  );
  // Backfill logo_url from shop_logo where empty
  await conn.query(
    `UPDATE shop_settings SET logo_url = shop_logo WHERE (logo_url IS NULL OR logo_url = '') AND shop_logo IS NOT NULL AND shop_logo <> ''`
  );
  log('↻', 'shop_settings.logo_url backfilled from shop_logo');

  // ---------------------------------------------------------------------------
  // 3. Seed member_tiers (default 4 tiers) if empty
  // ---------------------------------------------------------------------------
  if (!(await tableHasRows(conn, 'member_tiers'))) {
    await conn.query(`
      INSERT INTO member_tiers
        (line_account_id, tier_code, tier_name, min_points, color, icon, discount_percent, benefits, sort_order, is_active)
      VALUES
        (NULL, 'bronze',   'Bronze',   0,    '#CD7F32', '🥉', 0, 'สมาชิกเริ่มต้น',                          1, 1),
        (NULL, 'silver',   'Silver',   500,  '#C0C0C0', '🥈', 3, 'ส่วนลด 3% ทุกคำสั่งซื้อ',                  2, 1),
        (NULL, 'gold',     'Gold',     2000, '#FFD700', '🥇', 5, 'ส่วนลด 5% + สิทธิพิเศษสมาชิก',            3, 1),
        (NULL, 'platinum', 'Platinum', 5000, '#E5E4E2', '💎', 10,'ส่วนลด 10% + บริการ VIP + ของขวัญ',       4, 1)
    `);
    log('+', 'member_tiers seeded with 4 default tiers');
  } else {
    log('•', 'member_tiers already has data — skip seed');
  }

  // ---------------------------------------------------------------------------
  // 4. Seed tier_settings (used by TierService primary path) if empty
  // ---------------------------------------------------------------------------
  if (!(await tableHasRows(conn, 'tier_settings'))) {
    await conn.query(`
      INSERT INTO tier_settings (line_account_id, name, min_points, multiplier, benefits, badge_color)
      VALUES
        (NULL, 'Bronze',   0,    1.00, 'สมาชิกเริ่มต้น',                      '#CD7F32'),
        (NULL, 'Silver',   500,  1.10, 'ส่วนลด 3% ทุกคำสั่งซื้อ',              '#C0C0C0'),
        (NULL, 'Gold',     2000, 1.25, 'ส่วนลด 5% + สิทธิพิเศษสมาชิก',        '#FFD700'),
        (NULL, 'Platinum', 5000, 1.50, 'ส่วนลด 10% + บริการ VIP + ของขวัญ',   '#E5E4E2')
    `);
    log('+', 'tier_settings seeded');
  } else {
    log('•', 'tier_settings already has data — skip seed');
  }

  // ---------------------------------------------------------------------------
  // 5. Seed miniapp_banners / sections / products if empty
  // ---------------------------------------------------------------------------
  if (!(await tableHasRows(conn, 'miniapp_banners'))) {
    await conn.query(`
      INSERT INTO miniapp_banners
        (title, subtitle, image_url, link_type, link_value, position, surface, display_order, is_active)
      VALUES
        ('สงกรานต์สาดความคุ้ม', 'ลดสูงสุด 60% + รับเพิ่มสูงสุด x4 PRO POINT', '/img/summer.png', 'url',     'https://cny.re-ya.com/shop', 'home_top', 'home', 1, 1),
        ('สมาชิกรับสิทธิพิเศษ', 'สะสมคะแนนแลกส่วนลด',                          '/img/summer.png', 'miniapp', '/rewards',                   'home_top', 'home', 2, 1)
    `);
    log('+', 'miniapp_banners seeded');
  } else {
    log('•', 'miniapp_banners already has data — skip seed');
  }

  if (!(await tableHasRows(conn, 'miniapp_home_sections'))) {
    await conn.query(`
      INSERT INTO miniapp_home_sections
        (section_key, title, subtitle, section_style, bg_color, text_color, countdown_ends_at, surface, display_order, is_active)
      VALUES
        ('flash_sale_demo', 'GOLD CONTAINER', '24 ชั่วโมงเท่านั้น!', 'flash_sale',         '#8B0000', '#FFFFFF', DATE_ADD(NOW(), INTERVAL 24 HOUR), 'home', 1, 1),
        ('recommended',     'สินค้าแนะนำ',    'คัดสรรมาเพื่อคุณ',      'horizontal_scroll',  NULL,      NULL,      NULL,                              'home', 2, 1)
    `);
    log('+', 'miniapp_home_sections seeded');

    const [[flash]]  = await conn.query(`SELECT id FROM miniapp_home_sections WHERE section_key = 'flash_sale_demo'`);
    const [[recomm]] = await conn.query(`SELECT id FROM miniapp_home_sections WHERE section_key = 'recommended'`);

    if (flash) {
      await conn.query(`
        INSERT INTO miniapp_home_products
          (section_id, title, short_description, image_url, original_price, sale_price, discount_percent,
           promotion_tags, promotion_label, badges, delivery_options, link_type, link_value, display_order, is_active)
        VALUES
          (?, 'เบญจรงค์ ข้าวหอม 100% 1 กก. x 5',      '1 กก. x 5', '/img/summer.png', 175, 132, 24, ?, '24 ชม.', ?, ?, 'url',  'https://cny.re-ya.com/shop/product/1', 1, 1),
          (?, 'คาร์เนชัน เอ็กซ์ตร้า ครีมเทียม',       '1 ล. x 12', '/img/summer.png', 723, 720, 0,  ?, '24 ชม.', ?, ?, 'url',  'https://cny.re-ya.com/shop/product/2', 2, 1),
          (?, 'เบสท์ฟู้ดส์ สปาเกตตี้ 1 กก.',            '1 กก.',      '/img/summer.png', 125, 89,  28, ?, '24 ชม.', ?, ?, 'none', '',                                       3, 1)
      `, [
        flash.id, JSON.stringify(['ซื้อ 999฿ รับส่วนลด 10฿']), JSON.stringify([{text:'+2',color:'red'}]),    JSON.stringify(['สั่งเช้า ส่งเย็น','จำนวนจำกัด']),
        flash.id, JSON.stringify(['ซื้อ 499฿ ได้รับ 40 พอยท์']), JSON.stringify([{text:'3+ units',color:'orange'}]), JSON.stringify(['สั่งเช้า ส่งเย็น']),
        flash.id, JSON.stringify(['ซื้อ 499฿ ได้รับ 40 พอยท์']), JSON.stringify([]),                           JSON.stringify(['สั่งเช้า ส่งเย็น','จำนวนจำกัด']),
      ]);
    }
    if (recomm) {
      await conn.query(`
        INSERT INTO miniapp_home_products
          (section_id, title, short_description, image_url, original_price, sale_price, discount_percent, link_type, link_value, display_order, is_active)
        VALUES
          (?, 'วิตามินซี 1000mg',             'กระปุก 60 เม็ด', '/img/summer.png', 590, 490, 17, 'url',     'https://cny.re-ya.com/shop/product/10', 1, 1),
          (?, 'เจลล้างมือ แอลกอฮอล์ 70%',    '500ml',            '/img/summer.png', 199, 149, 25, 'miniapp', '/orders',                                 2, 1)
      `, [recomm.id, recomm.id]);
    }
    log('+', 'miniapp_home_products seeded');
  } else {
    log('•', 'miniapp_home_sections already has data — skip seed');
  }

  // ---------------------------------------------------------------------------
  // 6. Verification
  // ---------------------------------------------------------------------------
  const verify = [
    'miniapp_banners',
    'miniapp_home_sections',
    'miniapp_home_products',
    'member_tiers',
    'member_notification_preferences',
    'tier_settings',
  ];
  console.log('\n— Verification —');
  for (const t of verify) {
    const [[row]] = await conn.query(`SELECT COUNT(*) AS c FROM \`${t}\``);
    console.log(`  ${t.padEnd(35)} rows = ${row.c}`);
  }

  await conn.end();
  log('✔', 'Migration complete');
})().catch(err => {
  console.error('✖  Migration failed:', err.message);
  console.error(err);
  process.exit(1);
});
