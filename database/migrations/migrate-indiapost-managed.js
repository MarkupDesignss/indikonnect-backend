// Copy as src/db/migrate-indiapost-managed.js on the Node server.
import {readFile} from 'node:fs/promises';
import {pool} from '../db/pool.js';
const sql=await readFile(new URL('../../sql/004_indiapost_managed_awb.sql',import.meta.url),'utf8');
try {
  for(const statement of sql.replace(/^--[^\n]*$/gm,'').split(';').map(s=>s.trim()).filter(Boolean)) {
    await pool.query(statement);
  }
  console.log('Managed India Post AWB allocation tables created.');
} finally {await pool.end();}
