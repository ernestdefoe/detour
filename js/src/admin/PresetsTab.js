import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import ServerCheck from './ServerCheck';
import { t, request } from './util';

/** One-click rules for the addresses an old forum used, mapped through an Importer run when there is one. */
export default class PresetsTab extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.platforms = null;
    this.runs = [];
    this.importerRedirects = false;
    this.choice = {};
    this.busy = null;
    this.load();
  }

  load() {
    return request('GET', '/presets').then((response) => {
      this.platforms = response.platforms;
      this.runs = response.runs;
      this.importerRedirects = response.importerRedirects;
      m.redraw();
    });
  }

  view() {
    if (!this.platforms) return <LoadingIndicator />;

    return (
      <div className="DetourTab">
        <p className="helpText">{t('presets.help')}</p>
        {this.runs.length ? <p className="helpText">{t('presets.importer_help')}</p> : null}
        {this.importerRedirects ? <div className="Alert Alert--warning DetourWarning">{t('presets.importer_on')}</div> : null}

        <ServerCheck />

        <div className="DetourPresets">{this.platforms.map((p) => this.platform(p))}</div>
      </div>
    );
  }

  platform(p) {
    const runs = this.runs.filter((run) => run.source === p.key);
    const options = { '': t('presets.ids_kept') };
    runs.forEach((run) => {
      options[run.id] = t('presets.run', { id: run.id, status: run.status, topics: (run.mapped && run.mapped.topic) || 0 });
    });

    const selected = this.choice[p.key] ?? (p.runId ? String(p.runId) : runs.length ? String(runs[0].id) : '');

    return (
      <div className={`DetourPreset ${p.installed ? 'DetourPreset--on' : ''}`}>
        <div className="DetourPreset-head">
          <h4>{t(`platforms.${p.key}`)}</h4>
          {p.installed ? (
            <span className="DetourBadge DetourBadge--covered">{t('presets.installed', { count: p.installed, hits: p.hits })}</span>
          ) : null}
        </div>
        <details>
          <summary>{t('presets.addresses', { count: p.rules.length })}</summary>
          <ul className="DetourPreset-rules">
            {p.rules.map((r) => (
              <li>
                <span className="DetourMono">{r.example}</span> → {t(`lookups.${r.lookup}`)}
              </li>
            ))}
          </ul>
        </details>
        <Select value={selected} onchange={(v) => (this.choice[p.key] = v)} options={options} />
        <div className="DetourPreset-actions">
          <Button className="Button Button--primary" loading={this.busy === p.key} onclick={() => this.apply(p, selected, false)}>
            {p.installed ? t('presets.reinstall') : t('presets.install')}
          </Button>
          {p.installed ? (
            <Button className="Button" onclick={() => this.apply(p, selected, true)}>
              {t('presets.remove')}
            </Button>
          ) : null}
        </div>
      </div>
    );
  }

  apply(p, runId, remove) {
    if (remove && !confirm(t('presets.remove_confirm'))) return;
    this.busy = p.key;

    request('POST', '/presets', { platform: p.key, runId: runId || null, remove })
      .then((response) => {
        app.alerts.show({ type: 'success' }, remove ? t('presets.removed') : t('presets.done', { count: response.created }));
        return this.load();
      })
      .finally(() => {
        this.busy = null;
        m.redraw();
      });
  }
}
