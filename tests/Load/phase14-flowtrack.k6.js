import http from 'k6/http';
import { check, group, sleep } from 'k6';

const BASE_URL = (__ENV.FLOWTRACK_BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const PROFILE = __ENV.FLOWTRACK_LOAD_PROFILE || 'smoke';
const usersFile = __ENV.FLOWTRACK_LOAD_USERS_FILE || './tests/Load/users.example.json';
const users = JSON.parse(open(usersFile));

const profiles = {
  smoke: [{ duration: '20s', target: 2 }, { duration: '10s', target: 0 }],
  expected: [{ duration: '1m', target: 10 }, { duration: '3m', target: 25 }, { duration: '1m', target: 0 }],
  headroom: [{ duration: '1m', target: 25 }, { duration: '3m', target: 50 }, { duration: '2m', target: 75 }, { duration: '1m', target: 0 }],
};

const stages = profiles[PROFILE] || profiles.smoke;
const maxVus = Math.max(...stages.map((stage) => stage.target));
if (users.length < maxVus && __ENV.FLOWTRACK_ALLOW_USER_REUSE !== 'true') {
  throw new Error(`Load profile ${PROFILE} needs ${maxVus} distinct active users because FlowTrack enforces one live session per account. ${users.length} supplied.`);
}

export const options = {
  stages,
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{type:standard}': ['p(95)<500'],
    'http_req_duration{type:heavy}': ['p(95)<1000'],
    checks: ['rate>0.99'],
  },
};

function csrfToken(html) {
  const match = html.match(/name=["']_token["'][^>]*value=["']([^"']+)["']/i)
    || html.match(/value=["']([^"']+)["'][^>]*name=["']_token["']/i);
  return match ? match[1] : '';
}

function login() {
  const index = (__VU - 1) % users.length;
  const user = users[index];
  const loginPage = http.get(`${BASE_URL}/login`, { tags: { type: 'standard', screen: 'login' } });
  const token = csrfToken(loginPage.body || '');
  check(loginPage, { 'login page 200': (r) => r.status === 200, 'csrf present': () => token.length > 10 });

  const response = http.post(`${BASE_URL}/login`, {
    _token: token,
    email: user.email,
    password: user.password,
  }, {
    redirects: 0,
    tags: { type: 'standard', screen: 'login-submit' },
    headers: { Referer: `${BASE_URL}/login` },
  });

  check(response, { 'login accepted': (r) => r.status === 302 && !String(r.headers.Location || '').includes('/login') });
}

export default function () {
  login();

  group('authenticated operational reads', () => {
    const pages = [
      ['/dashboard', 'heavy', 'dashboard'],
      ['/orders', 'standard', 'orders'],
      ['/inquiries', 'standard', 'inquiries'],
      ['/my-work', 'standard', 'my-work'],
    ];

    for (const [path, type, screen] of pages) {
      const response = http.get(`${BASE_URL}${path}`, { tags: { type, screen } });
      check(response, { [`${screen} 200`]: (r) => r.status === 200 });
      sleep(0.3);
    }
  });

  sleep(1);
}
