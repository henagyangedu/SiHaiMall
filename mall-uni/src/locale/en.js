export default {
  common: {
    requestFailed: 'Request failed', networkError: 'Network error, please try again', loading: 'Loading…', noMore: 'No more items', empty: 'Nothing here yet',
    confirm: 'Confirm', cancel: 'Cancel', save: 'Save', delete: 'Delete', edit: 'Edit', submit: 'Submit', ok: 'OK', saved: 'Saved', deleted: 'Deleted',
    more: 'View more', all: 'All', retry: 'Retry',
  },
  tab: { home: 'Home', category: 'Categories', cart: 'Cart', user: 'Account' },
  home: { search: 'Search products', notice: 'Notice', recommend: 'Recommended', sold: '{n} sold', soldOut: 'Sold out' },
  category: { all: 'All products', sortDefault: 'Featured', sortSales: 'Best selling', sortPrice: 'Price', sortNew: 'Newest' },
  goods: {
    detail: 'Product', stock: '{n} in stock', select: 'Select', selected: 'Selected', qty: 'Quantity', addCart: 'Add to cart', buyNow: 'Buy now',
    added: 'Added to cart', chooseSpec: 'Please select options', noStock: 'Not enough stock', description: 'Details', offShelf: 'This product is unavailable',
  },
  cart: {
    title: 'Cart', empty: 'Your cart is empty', goShopping: 'Start shopping', selectAll: 'All', total: 'Total', checkout: 'Checkout ({n})',
    invalid: 'Unavailable', clearInvalid: 'Remove unavailable items', onlyLeft: 'Only {n} left', chooseFirst: 'Select items first', removeConfirm: 'Remove the selected items?',
  },
  login: {
    title: 'Sign in', register: 'Sign up', account: 'Email or phone', password: 'Password', nickname: 'Nickname (optional)', code: 'Verification code', getCode: 'Get code',
    resend: 'Resend in {n}s', inviteCode: 'Invite code (optional)', submitLogin: 'Sign in', submitRegister: 'Create account', toRegister: 'New here? Create an account', toLogin: 'Have an account? Sign in',
    needAccount: 'Enter your email or phone', needPassword: 'Password must be at least 6 characters', codeSent: 'Code sent', welcome: 'Welcome back', agree: 'By signing up you agree to the',
  },
  checkout: {
    title: 'Checkout', addAddress: 'Add a shipping address', goods: 'Subtotal', shipping: 'Shipping', discount: 'Member discount', points: 'Points',
    usePoints: 'Use {n} points to save {amount}', remark: 'Order note (optional)', pay: 'Total', submit: 'Place order', region: 'Priced for region "{name}"',
    needAddress: 'Please choose a shipping address', free: 'Free',
  },
  order: {
    list: 'My orders', detail: 'Order details', tabAll: 'All', tabUnpaid: 'Unpaid', tabToShip: 'Processing', tabShipped: 'Shipped', tabFinished: 'Completed',
    no: 'Order no.', createdAt: 'Placed on', payNow: 'Pay now', cancel: 'Cancel order', receive: 'Confirm receipt', cancelConfirm: 'Cancel this order?',
    receiveConfirm: 'Have you received your items?', cancelled: 'Order cancelled', received: 'Receipt confirmed', count: '{n} item(s)', payAmount: 'Paid',
    address: 'Shipping address', express: 'Tracking', payChannel: 'Choose a payment method', noChannel: 'No payment method is available right now. Please contact support.', expireAt: 'Please pay before {time}',
    paidSuccess: 'Payment successful', payPending: 'Payment is processing. Please check your order later.', remark: 'Note',
  },
  address: {
    list: 'Addresses', add: 'Add address', edit: 'Edit address', name: 'Recipient', phone: 'Phone', country: 'Country/Region', province: 'State/Province', city: 'City',
    district: 'District', detail: 'Street address', postcode: 'Postal code', setDefault: 'Set as default', default: 'Default', empty: 'No saved addresses yet',
    choose: 'Select', deleteConfirm: 'Delete this address?', needName: 'Enter the recipient name', needPhone: 'Enter a phone number', needDetail: 'Enter the street address', needRegion: 'Select province and city',
  },
  user: {
    title: 'Account', notLogin: 'Sign in', orders: 'My orders', address: 'Addresses', language: 'Language', currency: 'Currency', logout: 'Sign out',
    logoutConfirm: 'Sign out now?', points: 'Points', balance: 'Balance',
  },
}
