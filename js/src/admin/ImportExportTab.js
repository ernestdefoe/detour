import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import { t, request, api } from './util';

/** Redirects in and out as CSV. */
export default class ImportExportTab extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.csv = Stream('');
    this.result = null;
    this.loading = false;
  }

  view() {
    return (
      <div className="DetourTab">
        <h4>{t('csv.export_title')}</h4>
        <p className="helpText">{t('csv.export_help')}</p>
        <a className="Button" href={api('/export')} download="detour-redirects.csv">
          <i className="fas fa-download" aria-hidden="true" /> {t('csv.export')}
        </a>

        <h4>{t('csv.import_title')}</h4>
        <p className="helpText">{t('csv.import_help')}</p>
        <pre className="DetourMono DetourExample">{'/old-page,/d/12-new-page\n/viewtopic.php?t=7,/d/7,301\n/retired-section,,410'}</pre>
        <input type="file" accept=".csv,text/csv,text/plain" onchange={(e) => this.read(e)} />
        <textarea className="FormControl DetourMono DetourImport-text" rows="8" bidi={this.csv} placeholder="source,target,status" />
        <div className="DetourToolbar">
          <Button className="Button" loading={this.loading} onclick={() => this.send(true)}>
            {t('csv.check')}
          </Button>
          <Button className="Button Button--primary" loading={this.loading} onclick={() => this.send(false)}>
            {t('csv.import')}
          </Button>
        </div>

        {this.result ? (
          <div className="DetourImport-result">
            <p>{t(this.result.dryRun ? 'csv.result_check' : 'csv.result', { created: this.result.created, failed: this.result.failed })}</p>
            {this.result.truncated ? <p>{t('csv.truncated')}</p> : null}
            {this.result.errors.length ? (
              <ul>
                {this.result.errors.map((e) => (
                  <li>{t('csv.error_line', { line: e.line, message: e.message })}</li>
                ))}
              </ul>
            ) : null}
          </div>
        ) : null}
      </div>
    );
  }

  read(e) {
    const file = e.target.files[0];
    if (!file) return;
    file.text().then((text) => {
      this.csv(text);
      m.redraw();
    });
  }

  send(dryRun) {
    if (!this.csv().trim()) return;
    this.loading = true;

    request('POST', '/import', { csv: this.csv(), dryRun })
      .then((result) => {
        this.result = result;
        if (!dryRun && result.created) app.alerts.show({ type: 'success' }, t('csv.imported', { count: result.created }));
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
