import './styles.css';
import AdminApp from './AdminApp';
import BookingApp from './BookingApp';

// Lightweight path-based routing (no router dependency):
//   /admin*  -> barber's admin app (token-gated)
//   *        -> public booking page (customers arrive here from Instagram)
export default function App() {
  const isAdmin = window.location.pathname.startsWith('/admin');
  return isAdmin ? <AdminApp /> : <BookingApp />;
}
