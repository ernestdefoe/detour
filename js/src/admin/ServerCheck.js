import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import { t } from './util';

const EXTENSIONS = ['php', 'html', 'asp', 'xml'];

/**
 * Many web servers answer addresses ending in .html, .xml or .php themselves and
 * never hand them to Flarum (Flarum's own nginx example does this for .html and
 * .xml). This asks the server for a made-up address of each kind and reports
 * which ones reach Detour, so nobody wonders why a rule never fires.
 */
export default class ServerCheck extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.results = null;
    this.loading = false;
  }

  view() {
    const blocked = this.results ? EXTENSIONS.filter((ext) => !this.results[ext]) : [];
    // .php needs its own fix: a separate block that serves existing .php files
    // as files would hand out source code.
    const others = blocked.filter((ext) => ext !== 'php');

    return (
      <div className="DetourServerCheck">
        <h4>{t('server.title')}</h4>
        <p className="helpText">{t('server.help')}</p>
        <Button className="Button" loading={this.loading} onclick={() => this.run()}>
          {t('server.run')}
        </Button>
        {this.results ? (
          <ul className="DetourServerCheck-results">
            {EXTENSIONS.map((ext) => (
              <li className={this.results[ext] ? 'is-ok' : 'is-blocked'}>
                <i className={this.results[ext] ? 'fas fa-check' : 'fas fa-times'} aria-hidden="true" /> .{ext} —{' '}
                {this.results[ext] ? t('server.ok') : t('server.blocked')}
              </li>
            ))}
          </ul>
        ) : null}
        {blocked.includes('php') ? (
          <div className="DetourServerCheck-fix">
            <p>{t('server.fix_php')}</p>
            <pre className="DetourMono">{'location ~ \\.php$ {\n  try_files $uri /index.php?$query_string;\n  …\n}'}</pre>
            <p className="helpText">{t('server.fix_php_help')}</p>
          </div>
        ) : null}
        {others.length ? (
          <div className="DetourServerCheck-fix">
            <p>{t('server.fix', { exts: others.map((e) => '.' + e).join(', ') })}</p>
            <pre className="DetourMono">{`location ~* \\.(?:${others.join('|')})$ {\n  try_files $uri /index.php?$query_string;\n}`}</pre>
          </div>
        ) : null}
        {blocked.length ? <p className="helpText">{t('server.fix_help')}</p> : null}
      </div>
    );
  }

  run() {
    this.loading = true;
    const base = app.forum.attribute('baseUrl');

    Promise.all(
      EXTENSIONS.map((ext) =>
        fetch(`${base}/detour-probe.${ext}`, { credentials: 'omit', cache: 'no-store' })
          .then((r) => r.headers.get('X-Detour-Probe') === 'ok')
          .catch(() => false)
      )
    ).then((answers) => {
      this.results = {};
      EXTENSIONS.forEach((ext, i) => (this.results[ext] = answers[i]));
      this.loading = false;
      m.redraw();
    });
  }
}
