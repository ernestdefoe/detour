import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import Stream from 'flarum/common/utils/Stream';
import RedirectModal from './RedirectModal';
import TestBox from './TestBox';
import { t, request } from './util';

/** Every redirect, in the order the forum checks them, with search, edit and drag-to-reorder. */
export default class RedirectsTab extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.q = Stream('');
    this.type = Stream('');
    this.sort = Stream('');
    this.items = [];
    this.total = 0;
    this.loading = true;
    this.dragging = null;
    this.load();
  }

  load(more = false) {
    this.loading = true;
    const params = { q: this.q(), type: this.type(), sort: this.sort(), offset: more ? this.items.length : 0 };

    return request('GET', '/redirects', null, params).then((response) => {
      this.items = more ? this.items.concat(response.data) : response.data;
      this.total = response.total;
      this.loading = false;
      m.redraw();
    });
  }

  view() {
    // Dragging only reorders when the whole regex list is on screen in its real order.
    const sortable = this.sort() === '' && this.q() === '' && (this.type() === '' || this.type() === 'regex') && this.items.length >= this.total;

    return (
      <div className="DetourTab">
        <TestBox />

        <div className="DetourToolbar">
          <input
            className="FormControl DetourToolbar-search"
            type="search"
            placeholder={t('redirects.search')}
            value={this.q()}
            oninput={(e) => {
              this.q(e.target.value);
              clearTimeout(this.timer);
              this.timer = setTimeout(() => this.load(), 300);
            }}
          />
          <Select
            value={this.type()}
            onchange={(v) => {
              this.type(v);
              this.load();
            }}
            options={{ '': t('redirects.all_types'), exact: t('types.exact'), prefix: t('types.prefix'), regex: t('types.regex') }}
          />
          <Select
            value={this.sort()}
            onchange={(v) => {
              this.sort(v);
              this.load();
            }}
            options={{ '': t('redirects.sort_order'), hits: t('redirects.sort_hits'), recent: t('redirects.sort_recent') }}
          />
          <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => this.edit()}>
            {t('redirects.add')}
          </Button>
        </div>

        {this.items.length === 0 && !this.loading ? <p className="DetourEmpty">{t('redirects.empty')}</p> : null}

        {this.items.length ? (
          <table className="DetourTable">
            <thead>
              <tr>
                <th />
                <th>{t('redirects.col_from')}</th>
                <th>{t('redirects.col_to')}</th>
                <th>{t('redirects.col_hits')}</th>
                <th />
              </tr>
            </thead>
            <tbody>{this.items.map((r) => this.row(r, sortable && r.type === 'regex'))}</tbody>
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

  row(r, draggable) {
    const to =
      r.status === 410
        ? t('redirects.gone')
        : r.lookup
          ? t('redirects.lookup', { kind: t(`lookups.${r.lookup}`), run: r.runId ? t('redirects.via_run', { id: r.runId }) : '' })
          : r.target;

    return (
      <tr
        key={r.id}
        className={`DetourRow ${r.enabled ? '' : 'DetourRow--off'} ${this.dragging === r.id ? 'DetourRow--dragging' : ''}`}
        draggable={draggable}
        ondragstart={draggable ? (e) => this.dragStart(e, r) : undefined}
        ondragover={draggable ? (e) => this.dragOver(e, r) : undefined}
        ondrop={draggable ? (e) => e.preventDefault() : undefined}
        ondragend={draggable ? (e) => this.dragEnd(e) : undefined}
      >
        <td className="DetourRow-type">
          {draggable ? <i className="fas fa-grip-vertical DetourRow-grip" aria-hidden="true" /> : null}
          <span className={`DetourBadge DetourBadge--${r.type}`}>{t(`types.${r.type}`)}</span>
          {r.mode === 'missing' ? (
            <span className="DetourBadge DetourBadge--missing" title={t('modes.missing')}>
              {t('redirects.if_missing')}
            </span>
          ) : null}
        </td>
        <td className="DetourMono DetourRow-source">
          {r.source}
          {r.note ? <div className="DetourRow-note">{r.note}</div> : null}
        </td>
        <td className="DetourRow-target">
          <span className="DetourStatus">{r.status}</span> <span className="DetourMono">{to}</span>
          {r.external ? <i className="fas fa-external-link-alt DetourRow-ext" title={t('redirects.external')} /> : null}
        </td>
        <td className="DetourRow-hits" data-label={t('redirects.col_hits')}>
          {r.hits}
          {r.lastHitAt ? <div className="DetourRow-when">{humanTime(r.lastHitAt)}</div> : null}
        </td>
        <td className="DetourRow-actions">
          <Button
            className="Button Button--icon Button--link"
            icon={r.enabled ? 'fas fa-toggle-on' : 'fas fa-toggle-off'}
            aria-label={t('redirects.toggle')}
            onclick={() => this.toggle(r)}
          />
          <Button
            className="Button Button--icon Button--link"
            icon="fas fa-pencil-alt"
            aria-label={t('redirects.edit')}
            onclick={() => this.edit(r)}
          />
          <Button
            className="Button Button--icon Button--link"
            icon="fas fa-trash-alt"
            aria-label={t('redirects.delete')}
            onclick={() => this.remove(r)}
          />
        </td>
      </tr>
    );
  }

  edit(redirect) {
    app.modal.show(RedirectModal, { redirect, onsave: () => this.load() });
  }

  toggle(r) {
    request('PATCH', `/redirects/${r.id}`, { enabled: !r.enabled })
      .then((response) => {
        Object.assign(r, response.data);
        m.redraw();
      })
      .catch(() => {});
  }

  remove(r) {
    if (!confirm(t('redirects.delete_confirm', { source: r.source }))) return;

    request('DELETE', `/redirects/${r.id}`).then(() => {
      this.items = this.items.filter((x) => x !== r);
      this.total--;
      m.redraw();
    });
  }

  dragStart(e, r) {
    this.dragging = r.id;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(r.id));
  }

  dragOver(e, r) {
    e.preventDefault();
    if (this.dragging === null || this.dragging === r.id) return;

    const from = this.items.findIndex((x) => x.id === this.dragging);
    const to = this.items.indexOf(r);
    const [moved] = this.items.splice(from, 1);
    this.items.splice(to, 0, moved);
  }

  dragEnd(e) {
    if (this.dragging === null) return;
    this.dragging = null;

    // Dropped outside the list, or cancelled with Esc: put the order back.
    if (e.dataTransfer.dropEffect === 'none') {
      this.load();
      return;
    }

    const ids = this.items.filter((x) => x.type === 'regex').map((x) => x.id);
    request('POST', '/redirects/order', { ids }).then(() => m.redraw());
  }
}
