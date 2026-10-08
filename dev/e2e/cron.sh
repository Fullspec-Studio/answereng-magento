#!/usr/bin/env bash
# Run an AnswerEng cron job NOW through Magento's real cron runner (bin/magento cron:run --group=default).
#   dev/e2e/cron.sh                       -> answereng_sync_dirty (queued on its latest */5 slot)
#   dev/e2e/cron.sh answereng_reconcile   -> nightly job: its schedule is overridden to '* * * * *'
#                                            in core_config_data for the duration of this run only
# Magento drops pending rows whose time does not match the job's cron expression, hence the slot maths.
job="${1:-answereng_sync_dirty}"
d="$(dirname "$0")"
step=300
if [ "$job" = answereng_reconcile ]; then
  step=60
  "$d/sql.sh" "insert into core_config_data (scope,scope_id,path,value) values ('default',0,'crontab/default/jobs/answereng_reconcile/schedule/cron_expr','* * * * *') on duplicate key update value=values(value)"
  "$d/mage.sh" cache:clean config >/dev/null
fi
"$d/sql.sh" "delete from cron_schedule where job_code like 'answereng%' and status='pending';
  insert into cron_schedule (job_code,status,created_at,scheduled_at) values ('$job','pending',utc_timestamp(),from_unixtime(floor(unix_timestamp(utc_timestamp())/$step)*$step));"
"$d/mage.sh" cron:run --group=default >/dev/null 2>&1
if [ "$job" = answereng_reconcile ]; then
  "$d/sql.sh" "delete from core_config_data where path='crontab/default/jobs/answereng_reconcile/schedule/cron_expr'"
  "$d/mage.sh" cache:clean config >/dev/null
fi
"$d/sql.sh" "delete from cron_schedule where job_code like 'answereng%' and status='pending';
  select job_code,status,left(messages,200) messages,executed_at,finished_at from cron_schedule where job_code like 'answereng%' and executed_at is not null order by schedule_id desc limit 2"
