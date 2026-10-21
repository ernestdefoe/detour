import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import { t, request } from './util';

/** "What happens to this address?": the answer the forum would give, chain included. */
export default class TestBox extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.url = Stream('');
    this.result = null;
    this.loading = false;
  }

  view() {
    return (
      <form className="DetourTest" onsubmit={(e) => this.test(e)}>
        <label for="detour-test">{t('test.label')}</label>
        <div className="DetourTest-row">
          <input id="detour-test" className="FormControl DetourMono" bidi={this.url} placeholder="/viewtopic.php?t=123" />
          <Button type="submit" className="Button" loading={this.loading}>
            {t('test.submit')}
          </Button>
        </div>
        {this.result ? <div className="DetourTest-result">{this.describe(this.result)}</div> : null}
      </form>
    );
  }

  describe(r) {
    if (!r.valid) return t('test.invalid');
    if (r.reserved) return t('test.reserved');
    if (!r.always && !r.missing) return t('test.none');

    const line = (hit, key) =>
      hit ? (
        <p>
          {t(key, { id: hit.ruleId, status: hit.status })} {hit.target ? <code>{hit.target}</code> : t('test.gone')}
          {hit.chain.length ? ' ' + t('test.chain', { ids: hit.chain.map((id) => '#' + id).join(', ') }) : ''}
        </p>
      ) : null;

    return [line(r.always, 'test.always'), r.always ? null : line(r.missing, 'test.missing')];
  }

  test(e) {
    e.preventDefault();
    if (!this.url().trim()) return;
    this.loading = true;

    request('POST', '/test', { url: this.url().trim() })
      .then((result) => {
        this.result = result;
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
