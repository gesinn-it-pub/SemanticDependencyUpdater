WITH q AS (
  SELECT CAST(argument AS CHAR) AS s FROM mysql.general_log
  WHERE command_type = 'Query' AND user_host LIKE 'wiki%'
)
SELECT 'q_total', COUNT(*) FROM q
UNION ALL SELECT 'q_select', COUNT(*) FROM q
  WHERE s REGEXP '^(/\\*[^*]*\\*/ *)?SELECT'
UNION ALL SELECT 'q_write', COUNT(*) FROM q
  WHERE s REGEXP '^(/\\*[^*]*\\*/ *)?(INSERT|UPDATE|DELETE|REPLACE)'
UNION ALL SELECT 'q_objectcache', COUNT(*) FROM q
  WHERE s LIKE '%objectcache%'
UNION ALL SELECT 'q_objectcache_sdu', COUNT(*) FROM q
  WHERE s LIKE '%objectcache%' AND s LIKE '%sdu%'
UNION ALL SELECT 'q_job', COUNT(*) FROM q
  WHERE s REGEXP '[` ]job[` ]'
UNION ALL SELECT 'q_smw', COUNT(*) FROM q
  WHERE s LIKE '%smw\\_%'
UNION ALL SELECT 'q_page_rev', COUNT(*) FROM q
  WHERE s REGEXP '[` ](page|revision|slots|content|text)[` ]';
