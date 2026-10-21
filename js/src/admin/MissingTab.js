import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import Stream from 'flarum/common/utils/Stream';
import RedirectModal from './RedirectModal';
import { t, request, api, decode } from './util';

/** The 404 log: addresses visitors asked for that did not exist, most-hit first. */
export default class MissingTab extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.q = Stream('');
    this.sort = Stream('hits');
    this.ignored = Stream(false);
    this.items = [];
    this.total = 0;
    this.loading = true;
    this.load();
  }

  load(more = false) {
    this.loading = true;
    const params = { q: this.q(), sort: this.sort(), ignored: this.ignored() ? 1 : 0, offset: more ? this.items.length : 0 };

    return request('GET', '/missing', null, params).then((response) => {
      this.items = more ? this.items.concat(response.data) : response.data;
      this.total = response.total;
      this.loading = false;
      m.redraw();
    });
  }

  view() {
    return (
      <div className="DetourTab">
        <p className="helpText">{t('missing.help')}</p>

        <div className="DetourToolbar">
          <input
            className="FormControl DetourToolbar-search"
            type="search"
            placeholder={t('missing.search')}
            value={this.q()}
            oninput={(e) => {
              this.q(e.target.value);
              clearTimeout(this.timer);
              this.timer = setTimeout(() => this.load(), 300);
            }}
          />
          <Select
            value={this.sort()}
            onchange={(v) => {
              this.sort(v);
              this.load();
            }}
            options={{ hits: t('missing.sort_hits'), recent: t('missing.sort_recent') }}
          />
          <Switch
            state={this.ignored()}
            onchange={(v) => {
              this.ignored(v);
              this.load();
            }}
          >
            {t('missing.show_ignored')}
          </Switch>
          <a className="Button" href={api('/export?log=1')} download="detour-404-log.csv">
            {t('missing.export')}
          </a>
          <Button className="Button" icon="fas fa-broom" onclick={() => this.clear()}>
            {t('missing.clear')}
          </Button>
        </div>

        {this.items.length === 0 && !this.loading ? <p className="DetourEmpty">{t('missing.empty')}</p> : null}

        {this.items.length ? (
          <table className="DetourTable">
            <thead>
              <tr>
                <th>{t('missing.col_url')}</th>
                <th>{t('missing.col_hits')}</th>
                <th>{t('missing.col_seen')}</th>
                <th />
              </tr>
            </thead>
            <tbody>{this.items.map((row) => this.row(row))}</tbody>
          </table>
        ) : null}

        {this.loading ? <LoadingIndicator /> : null}

        {!this.loading && this.items.length < this.total ? (
          <Button className="Button" onclick={() => this.load(true)}>
            {t('load_more')}
          </Button>
        ) : null}
      </div>
    );
  }

  row(row) {
    return (
      <tr key={row.id} className="DetourRow">
        <td className="DetourRow-source">
          <span className="DetourMono">{decode(row.url)}</span>
          {row.ruleId ? <span className="DetourBadge DetourBadge--covered">{t('missing.covered', { id: row.ruleId })}</span> : null}
          {row.referrers.length ? (
            <details className="DetourRow-referrers">
              <summary>{t('missing.referrers', { count: row.referrers.length })}</summary>
              <ul>
                {row.referrers.map((ref) => (
                  <li className="DetourMono">{ref}</li>
                ))}
              </ul>
            </details>
          ) : null}
        </td>
        <td className="DetourRow-hits" data-label={t('missing.col_hits')}>
          {row.hits}
        </td>
        <td className="DetourRow-when">
          {humanTime(row.lastSeenAt)}
          <div>{t('missing.first_seen', { time: humanTime(row.firstSeenAt) })}</div>
        </td>
        <td className="DetourRow-actions">
          <Button
            className="Button Button--icon Button--link"
            icon="fas fa-route"
            aria-label={t('missing.redirect')}
            title={t('missing.redirect')}
            onclick={() => this.redirect(row)}
          />
          <Button
            className="Button Button--icon Button--link"
            icon={row.ignored ? 'fas fa-eye' : 'fas fa-eye-slash'}
            aria-label={row.ignored ? t('missing.unignore') : t('missing.ignore')}
            title={row.ignored ? t('missing.unignore') : t('missing.ignore')}
            onclick={() => this.ignore(row)}
          />
          <Button
            className="Button Button--icon Button--link"
            icon="fas fa-trash-alt"
            aria-label={t('missing.delete')}
            title={t('missing.delete')}
            onclick={() => this.remove(row)}
          />
        </td>
      </tr>
    );
  }

  redirect(row) {
    app.modal.show(RedirectModal, { source: row.url, onsave: () => this.load() });
  }

  ignore(row) {
    request('PATCH', `/missing/${row.id}`, { ignored: !row.ignored }).then(() => {
      this.items = this.items.filter((x) => x !== row);
      this.total--;
      m.redraw();
    });
  }

  remove(row) {
    request('DELETE', `/missing/${row.id}`).then(() => {
      this.items = this.items.filter((x) => x !== row);
      this.total--;
      m.redraw();
    });
  }

  clear() {
    if (!confirm(t('missing.clear_confirm'))) return;
    request('POST', '/missing/clear', { includeIgnored: this.ignored() }).then(() => this.load());
  }
}
