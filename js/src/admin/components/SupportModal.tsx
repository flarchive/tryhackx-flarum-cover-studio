import app from 'flarum/admin/app';
import Modal from 'flarum/common/components/Modal';
import extractText from 'flarum/common/utils/extractText';

const t = (key: string) => app.translator.trans(`tryhackx-cover-studio.admin.support.${key}`);

const WALLETS = [
  {
    name: 'Monero (XMR)',
    icon: 'fab fa-monero',
    address: '45hvee4Jv7qeAm6SrBzXb9YVjb8DkHtFtFh7qkDMxS9zYX3NRi1dV27MtSdVC5X8T1YVoiG8XFiJkh4p9UncqWGxHi4tiwk',
    color: '#ff6600',
  },
  {
    name: 'Bitcoin (BTC)',
    icon: 'fab fa-bitcoin',
    address: 'bc1qncavcek4kknpvykedxas8kxash9kdng990qed2',
    color: '#f7931a',
  },
  {
    name: 'Ethereum (ETH)',
    icon: 'fab fa-ethereum',
    address: '0xa3d38d5Cf202598dd782C611e9F43f342C967cF5',
    color: '#627eea',
  },
];

export default class SupportModal extends Modal {
  className() {
    return 'CoverStudio-SupportModal Modal--small';
  }

  title() {
    return [<i className="fas fa-heart" style={{ color: '#e74c3c', marginRight: '8px' }} />, t('title')];
  }

  content() {
    return (
      <div className="Modal-body">
        <p className="CoverStudio-SupportModal-description">{t('description')}</p>

        <div className="CoverStudio-SupportModal-wallets">
          {WALLETS.map((wallet) => (
            <div className="CoverStudio-SupportModal-wallet" key={wallet.name}>
              <div className="CoverStudio-SupportModal-walletHeader">
                <i className={wallet.icon} style={{ color: wallet.color }} />
                <span>{wallet.name}</span>
              </div>
              <div className="CoverStudio-SupportModal-walletAddress">
                <code>{wallet.address}</code>
                <button
                  className="Button Button--icon CoverStudio-SupportModal-copyBtn"
                  title={extractText(t('copy'))}
                  onclick={(e: MouseEvent) => this.copyAddress(e, wallet.address)}
                >
                  <i className="fas fa-copy" />
                </button>
              </div>
            </div>
          ))}
        </div>

        <p className="CoverStudio-SupportModal-thanks">{t('thanks')}</p>
      </div>
    );
  }

  copyAddress(e: MouseEvent, address: string) {
    const btn = e.currentTarget as HTMLButtonElement;
    if (!navigator.clipboard) return;

    navigator.clipboard
      .writeText(address)
      .then(() => {
        const icon = btn.querySelector('i');
        if (!icon) return;

        icon.className = 'fas fa-check';
        btn.classList.add('CoverStudio-SupportModal-copyBtn--copied');

        setTimeout(() => {
          icon.className = 'fas fa-copy';
          btn.classList.remove('CoverStudio-SupportModal-copyBtn--copied');
        }, 2000);
      })
      .catch(() => {});
  }
}
