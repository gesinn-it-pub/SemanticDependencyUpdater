#!/usr/bin/env bash
# SDU before/after performance harness. Runs against the project's own test wiki
# (see docs/performance.md). Container names can be overridden via SDU_WIKI / SDU_DB.
# usage: perf.sh setup | view <label> [N] | micro <label> | save <label> [runs]
#        | client <label> [runs] | compare <labelA> <labelB>
set -u
W=${SDU_WIKI:-semanticdependencyupdater-mysql-wiki-1}
M=${SDU_DB:-semanticdependencyupdater-mysql-mysql-1}
HERE=$(cd "$(dirname "$0")" && pwd)
OUT=$HERE/results
REPO=$(cd "$HERE/../.." && pwd)
EXT=/var/www/html/extensions/SemanticDependencyUpdater
URL=http://127.0.0.1:8080
MW='cd /var/www/html && sudo -u www-data php maintenance/run.php'
PARSES='/^PerfSite$/{s++} /^PerfChild/{c++}'
PARSES_OUT='printf "run%s_parses_site\t%d\nrun%s_parses_children\t%d\nrun%s_parses_total\t%d\n",r,s,r,c,r,s+c'
WEB_OUT='printf "run%s_web_views\t%d\nrun%s_web_views_parsed\t%d\nrun%s_web_api_purge\t%d\n'
WEB_OUT="$WEB_OUT"'run%s_web_api_status\t%d\nrun%s_web_requests_total\t%d\nrun%s_web_ms_total\t%d\n",'
WEB_OUT="$WEB_OUT"'r,v,r,vp,r,p,r,s,r,NR,r,ms'

dbq() { docker exec -i "$M" mariadb -uroot -pdatabase -N "$@"; }
wx() { docker exec "$W" bash -c "$1"; }
cap_start() {
	dbq -e "SET GLOBAL general_log=0; SET GLOBAL log_output='TABLE'; TRUNCATE mysql.general_log; SET GLOBAL general_log=1;"
}
cap_stop() { dbq -e "SET GLOBAL general_log=0;"; }
cap_report() { dbq < "$HERE/sql-metrics.sql"; }
queue_len() { dbq wiki -e "SELECT COUNT(*) FROM job;"; }
drain_jobs_quietly() { wx "$MW runJobs >/dev/null 2>&1"; }
require_empty_queue() {
	[ "$(queue_len)" = "0" ] || { echo "queue not empty before run $1"; exit 1; }
}
site_text() {
	printf '%s' "{{#set:Perf Status=$1}}{{#set:Perf Derived={{#show:PerfSite|?Perf Status}}}}"
	printf '%s\n' "{{#set:Semantic Dependency=PerfSite}}{{#set:Semantic Dependency=Perf Part of::PerfSite}}"
	printf '%s\n' "{{#ask:[[Perf Part of::PerfSite]]|?Perf Value|limit=50}}"
}
parse_counts() { wx 'cut -f3 /tmp/sdu-perf/parses.log' | awk -v r="$1" "$PARSES END{$PARSES_OUT}"; }

case "${1:-}" in
setup)  # sync the repo's tracked code into the test wiki (exact code under test)
	git -C "$REPO" ls-files extension.json src res i18n | tar -C "$REPO" -c -T - \
		| docker exec -i "$W" tar -x -C $EXT
	docker cp "$HERE/perf-include.php" "$W:/var/www/html/perf-include.php"
	for f in fixture hook-micro micro2; do docker cp "$HERE/$f.php" "$W:/tmp/sdu-perf/$f.php"; done
	echo "synced: $(git -C "$REPO" rev-parse --short HEAD) dirty=$(git -C "$REPO" status --porcelain | wc -l)" ;;
view)
	L=$2; N=${3:-300}; mkdir -p "$OUT/$L"; f="$OUT/$L/view.txt"; : > "$f"
	for page in PerfPlain PerfSite; do
		wx "for i in \$(seq 30); do curl -s -o /dev/null $URL/index.php?title=$page; done"  # warm-up
		cap_start
		wx "for i in \$(seq $N); do curl -s -o /dev/null -w '%{time_total}\n' $URL/index.php?title=$page; done" \
			> "$OUT/$L/view-$page.times"
		cap_stop
		python3 - "$OUT/$L/view-$page.times" "$page" >> "$f" <<'PY'
import sys, statistics as s
t = sorted(float(x) for x in open(sys.argv[1]).read().split())
p = sys.argv[2]
print(f"view_{p}_ms_median\t{s.median(t)*1000:.1f}")
print(f"view_{p}_ms_p95\t{t[int(len(t)*0.95)-1]*1000:.1f}")
PY
		cap_report | awk -v p="$page" -v n="$N" '{printf "view_%s_%s_per_req\t%.2f\n", p, $1, $2/n}' >> "$f"
	done; cat "$f" ;;
micro)
	L=$2; mkdir -p "$OUT/$L"; cap_start
	wx "$MW /tmp/sdu-perf/hook-micro.php" | grep hook_us | tee "$OUT/$L/micro.txt"
	cap_stop
	cap_report | awk '{printf "micro_%s_per_2000calls\t%s\n",$1,$2}' | tee -a "$OUT/$L/micro.txt" ;;
save)
	L=$2; R=${3:-3}; mkdir -p "$OUT/$L"; : > "$OUT/$L/save.txt"
	for run in $(seq "$R"); do
		drain_jobs_quietly; require_empty_queue "$run"
		wx ": > /tmp/sdu-perf/parses.log"
		cap_start
		t0=$(date +%s.%N)
		site_text "$L-$run-$RANDOM" | docker exec -i "$W" bash -c "$MW edit -u PerfBot -s perf PerfSite" >/dev/null
		jl="$OUT/$L/jobs-$run.log"; : > "$jl"
		idle=0
		while :; do
			wx "$MW runJobs 2>&1" >> "$jl"
			if [ "$(queue_len)" = "0" ]; then idle=$((idle+1)); else idle=0; fi
			[ $idle -ge 2 ] && break
			awk -v a="$(date +%s.%N)" -v b="$t0" 'BEGIN{exit !(a-b>180)}' && { echo "timeout"; break; }
			sleep 0.3
		done
		t1=$(date +%s.%N); cap_stop
		{
			# minus the 2 idle confirmation sleeps
			printf "run%s_wall_s\t%.2f\n" "$run" "$(awk -v a="$t1" -v b="$t0" 'BEGIN{print a-b-0.6}')"
			parse_counts "$run"
			awk -v r="$run" '$NF=="good"{n[$3]++; t++}
				END{printf "run%s_jobs_total\t%d\n",r,t; for(k in n) printf "run%s_jobs_%s\t%d\n",r,k,n[k]}' "$jl"
			cap_report | awk -v r="$run" '{printf "run%s_%s\t%s\n", r,$1,$2}'
		} >> "$OUT/$L/save.txt"
	done; cat "$OUT/$L/save.txt" ;;
client)
	L=$2; R=${3:-3}; mkdir -p "$OUT/$L"; : > "$OUT/$L/client.txt"
	docker cp "$HERE/client-cycle.js" "$W:/tmp/pw/client-cycle.js"
	for run in $(seq "$R"); do
		drain_jobs_quietly; require_empty_queue "$run"
		wx ": > /tmp/sdu-perf/parses.log; : > /tmp/sdu-perf/requests.log; chmod 666 /tmp/sdu-perf/*.log
			pkill -f sdu-job-loop; true"
		# background job runner, like production's external runner (1s tick)
		docker exec -d "$W" bash -c "exec -a sdu-job-loop bash -c 'cd /var/www/html;
			while true; do sudo -u www-data php maintenance/run.php runJobs >> /tmp/sdu-perf/runner.log 2>&1
			sleep 1; done'"
		cap_start
		wx "cd /tmp/pw && node client-cycle.js $L-$run-$RANDOM" \
			| awk -v r="$run" '{printf "run%s_%s\t%s\n", r,$1,$2}' >> "$OUT/$L/client.txt"
		# let the server side finish (queue empty three times), then stop the capture
		idle=0
		for i in $(seq 60); do
			if [ "$(queue_len)" = "0" ]; then idle=$((idle+1)); else idle=0; fi
			[ $idle -ge 3 ] && break
			sleep 1
		done
		cap_stop; wx "pkill -f sdu-job-loop; true"
		{
			parse_counts "$run"
			wx 'cat /tmp/sdu-perf/requests.log' | awk -F"\t" -v r="$run" \
				'$4=="view"{v++; if($5==1)vp++} $4=="api:purge"{p++} $4=="api:sduselfupdatestatus"{s++}
				{ms+=$2} END{'"$WEB_OUT"'}'
			cap_report | awk -v r="$run" '{printf "run%s_%s\t%s\n", r,$1,$2}'
		} >> "$OUT/$L/client.txt"
	done; cat "$OUT/$L/client.txt" ;;
compare)
	python3 "$HERE/compare.py" "$OUT" "$2" "$3" ;;
*) sed -n 2,5p "$0" ;;
esac
