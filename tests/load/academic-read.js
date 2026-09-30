import http from 'k6/http';
import {check, sleep} from 'k6';
import {SharedArray} from 'k6/data';

// No logins, writes, production credentials or insecure TLS overrides.
// Supply one independently authenticated STAGING session per simulated user.
if (__ENV.ALLOW_LOAD_TEST !== '1') throw new Error('Set ALLOW_LOAD_TEST=1 only against your staging deployment.');
const peak = Number(__ENV.VUS || 100);
if (!Number.isInteger(peak) || peak < 1 || peak > 1000) throw new Error('VUS must be 1..1000.');
const sessions = new SharedArray('staging sessions', () => JSON.parse(open(__ENV.SESSION_FILE || './sessions.local.json')));
if (sessions.length < peak) throw new Error('Provide a distinct staging session for every VU. Do not reuse one account for everyone.');
if (new Set(sessions.slice(0, peak).map(session => `${session.origin}|${session.cookie}`)).size !== peak) {
  throw new Error('Session cookies must be distinct for the simulated users.');
}
for (const session of sessions) {
  if (!/^https?:\/\/[^/]+$/.test(session.origin) || !session.cookie || !session.paths?.length) {
    throw new Error('Each session needs origin, cookie, and nonempty paths.');
  }
  if (!session.paths.every(path => /^\/api\//.test(path))) throw new Error('Paths must stay on the specified origin.');
}

export const options = {
  scenarios: {academic_readers: {
    executor: 'ramping-vus', startVUs: 0,
    stages: [{duration: '2m', target: peak}, {duration: '5m', target: peak}, {duration: '1m', target: 0}],
    gracefulRampDown: '30s',
  }},
  thresholds: {
    'http_req_failed{phase:bootstrap}': ['rate==0'],
    'http_req_failed{phase:read}': ['rate<0.01'],
    'http_req_duration{phase:read}': ['p(95)<500', 'p(99)<1500'],
    checks: ['rate>0.99'],
  },
  systemTags: ['status', 'method', 'name', 'scenario', 'expected_response'],
  summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)', 'max'],
};

let initialized = false;
export default function () {
  const session = sessions[__VU - 1];
  if (!initialized) {
    http.cookieJar().set(session.origin, 'school_saas_tenant', session.cookie);
    const identity = http.get(`${session.origin}/api/me`, {headers: {Accept: 'application/json'},
      tags: {name: 'session_bootstrap', phase: 'bootstrap'}});
    if (!check(identity, {'session is valid': r => r.status === 200 && Boolean(r.json('user.id'))})) {
      throw new Error('Invalid or expired staging session; do not interpret this run as capacity.');
    }
    initialized = true;
  }
  // Think time models active people rather than an unbounded busy loop.
  const index = Math.floor(Math.random() * session.paths.length);
  const response = http.get(session.origin + session.paths[index], {
    headers: {Accept: 'application/json'}, tags: {name: `academic_read_${index}`, phase: 'read'}, timeout: '10s',
  });
  check(response, {'academic data is returned': r => r.status === 200 && r.json('data') !== undefined});
  sleep(5 + Math.random() * 10);
}
