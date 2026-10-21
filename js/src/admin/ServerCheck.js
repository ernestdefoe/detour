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
        {blocked.length ? (
          <div className="DetourServerCheck-fix">
            <p>{t('server.fix', { exts: blocked.map((e) => '.' + e).join(', ') })}</p>
            <pre className="DetourMono">{`location ~* \\.(?:${blocked.join('|')})$ {\n  try_files $uri /index.php?$query_string;\n}`}</pre>
            <p className="helpText">{t('server.fix_help')}</p>
          </div>
        ) : null}
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
